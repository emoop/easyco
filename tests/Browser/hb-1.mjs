// A REAL-BROWSER run of HB-1: «Стил на блока» of an HTML block. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed (the migration of HB-1 must have run).
// It creates a throw-away admin («hb1-audit») and ONE block through the real form, BACKS UP the `site_settings` table, the `appearance/` files (with their contents) and the `style` of every existing
// HTML block first; at the end it restores them, removes the admin and the block it made, and verifies the settings table is byte-for-byte what it was (a checksum of every row). Prints a JSON report;
// exit code 1 when a check failed.
//
// Steps: create the block «Промокод Есен -10%» (green background #2e7d32, white text, padding 6/10, radius 4, auto width, centred) in the form (the preview shows it) → put it in the right column
// (Design → «Дясна колона» → HTML блок → Приложи → Публикувай) and in the footer → the LIVE product page and footer have the computed background, text colour, padding and radius, the served HTML has no
// `style=` and the token stylesheet carries the rule → change the colour in the form → the live page follows with no other click → «Без стил» → the wrapper and the rule are gone.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'hb1-audit@example.test';
const PASSWORD = 'Hb1-' + randomBytes(6).toString('hex');
const BLOCK_TITLE = 'Промокод Есен -10%';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-1.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// ---- the dev database, through the project's own code (tinker); one line per call ---------------------------------------------------------------
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
$blocks = DB::table('html_blocks')->orderBy('id')->get(['id', 'style'])->map(fn ($r) => (array) $r)->all();
file_put_contents(sys_get_temp_dir().'/hb1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'blocks' => $blocks, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HB1 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'blocks' => count($blocks), 'sum' => md5(json_encode($rows))]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb1_backup.json'), true);
DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->delete();
foreach ($bak['blocks'] as $b) { DB::table('html_blocks')->where('id', $b['id'])->update(['style' => $b['style']]); }
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$styles = DB::table('html_blocks')->orderBy('id')->get(['id', 'style'])->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'madeBlocks' => DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->count(), 'blocksSame' => md5(json_encode($styles)) === md5(json_encode($bak['blocks'])), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const blockId = () => php(`echo (string) DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->value('id');`);
const blockStyle = () => php(`echo (string) DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->value('style');`);
// the first visible product of the shop (the one the preview shows)
const firstProduct = () => php(`$s = app(App\\Storefront\\Reader\\CatalogReader::class)->listing(App\\Storefront\\ReadModels\\ListingQuery::fromRequestArray([]))->items[0] ?? null; echo $s === null ? '' : $s->slug;`);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 500) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let backedUp = false;

try {
    console.error('backup: ' + php(SETUP));
    backedUp = true;
    const slug = firstProduct();
    check('the shop has a visible product to look at', slug !== '', slug);

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1300 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    // `/api/cart` answers 500 («Session store not set on request») whenever a storefront page loads inside the admin session (the preview frame): an older, separate problem
    const cartApi500 = [];
    page.on('response', (r) => { if (r.status() >= 500) { r.url().endsWith('/api/cart') ? cartApi500.push(r.url()) : consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); } });
    const shot = async (name, locator) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await locator.screenshot({ path }); };
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    const toolbar = () => page.locator('.fi-header-actions-ctn .fi-btn');
    const button = (name) => toolbar().filter({ hasText: name }).first();
    const confirmModal = async () => { await sleep(700); if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };

    /** a fresh context's view of a live page: the served HTML (raw), the computed look of the first styled box inside `scope`, and the token stylesheet the page links */
    const live = async (url, scope) => {
        const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const raw = await (await ctx.request.get(BASE + url)).text();
        const p = await ctx.newPage();
        await p.goto(BASE + url, { waitUntil: 'networkidle' });
        const look = await p.evaluate((sel) => {
            const el = document.querySelector(sel + ' .sf-hb');

            if (! el) return null;

            const cs = getComputedStyle(el);

            return { cls: el.className, text: el.innerText.trim(), bg: cs.backgroundColor, color: cs.color, padding: cs.paddingTop + ' ' + cs.paddingRight, radius: cs.borderTopLeftRadius, align: cs.textAlign, width: cs.width };
        }, scope);
        const tokens = (raw.match(/href="([^"]*\/tokens-[0-9a-f]+\.css)"/) || [])[1];
        const css = tokens ? await (await ctx.request.get(tokens.startsWith('http') ? tokens : BASE + tokens)).text() : '';
        const box = p.locator(scope).first();
        OUT && await box.screenshot({ path: join(OUT, 'live-' + (shots.length) + '.png') }).then(() => shots.push(join(OUT, 'live-' + (shots.length) + '.png'))).catch(() => {});
        await ctx.close();

        return { raw, look, css };
    };

    // ---- the form: create the block ------------------------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    await page.fill('input[id="form.title"]', BLOCK_TITLE);
    await page.locator('.tiptap').first().click();
    await page.keyboard.type(BLOCK_TITLE);

    const field = (key) => page.locator(`[id="form.style.${key}"]`).first();
    const setText = async (key, value) => { await field(key).fill(value); await field(key).blur(); await sleep(900); };
    await setText('bg', '#2e7d32');
    await setText('color', '#ffffff');
    await setText('pad_v', '6');
    await setText('pad_h', '10');
    await setText('radius', '4');
    // «Подравняване»: a custom select
    await page.locator('.fi-fo-field', { hasText: 'Подравняване' }).first().locator('button, [role=combobox]').first().click();
    await sleep(500);
    await page.getByRole('option', { name: 'Центрирано' }).click();
    await sleep(900);

    const section = page.locator('.fi-section', { hasText: 'Стил на блока' }).first();
    const previewStyle = await section.locator('.fi-hb-preview > div').first().getAttribute('style');
    check('the live preview in the form shows the chosen values', /background:#2e7d32/.test(previewStyle ?? '') && /color:#ffffff/.test(previewStyle ?? '') && /padding:6px 10px/.test(previewStyle ?? '') && /border-radius:4px/.test(previewStyle ?? '') && /text-align:center/.test(previewStyle ?? ''), previewStyle ?? 'no preview');
    check('the preview shows the block\'s title as the sample text', (await section.locator('.fi-hb-preview').innerText()).includes(BLOCK_TITLE));
    check('no contrast warning for white on green', ! (await section.innerText()).includes('Малък контраст'));
    await shot('form-with-preview', section);

    await page.locator('button[type=submit]').filter({ hasText: /Създай|Create/ }).first().click();
    await settle();
    const id = blockId();
    check('the block is saved', id !== '', id);
    check('the stored style is the normalised JSON (only what differs from the defaults)', blockStyle().includes('"bg": "#2e7d32"') || blockStyle().includes('"bg":"#2e7d32"'), blockStyle());

    // ---- put it in the right column and in the footer -----------------------------------------------------------------------------------------
    const chooseBlock = async () => {
        await page.locator('.ds-props .fi-select-input, .ds-props .fi-fo-select button, .ds-props [role=combobox]').first().click();
        await sleep(500);
        await page.getByRole('option', { name: BLOCK_TITLE }).click();
        await sleep(500);
    };
    const applyAndPublish = async () => {
        await page.locator('.ds-props button[type=submit]').click();
        await settle();
        await button('Публикувай').click();
        await confirmModal();
        await sleep(2500);
    };

    await page.goto(BASE + '/admin/design?zone=summary', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await button('Върни публикуваното').click();
    await confirmModal();
    await page.locator('.ds-palette-chips .ds-chip-add', { hasText: 'HTML блок' }).click();
    await settle();
    await page.locator('.ds-chips-list > li[data-ds-component="html_block"] .ds-chip-main').last().click();
    await sleep(700);
    await chooseBlock();
    await applyAndPublish();

    await page.goto(BASE + '/admin/design?zone=footer', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await button('Върни публикуваното').click();
    await confirmModal();
    await page.locator('.ds-cell .ds-cell-title').first().click();
    await sleep(700);
    await page.locator('.ds-palette-chips .ds-chip-add', { hasText: 'HTML блок' }).click();
    await settle();
    await chooseBlock();
    await applyAndPublish();

    // ---- the LIVE pages ---------------------------------------------------------------------------------------------------------------------------
    const product = await live('/product/' + slug, '.sf-summary');
    const home = await live('/', '.sf-footer');
    const rule = new RegExp('\\.sf-hb-' + id + '\\{[^}]*background:#2e7d32');

    check('LIVE product page: the block is in the right column inside `.sf-hb`', product.look !== null && product.look.text.includes(BLOCK_TITLE) && product.look.cls.includes('sf-hb-' + id), JSON.stringify(product.look));
    check('LIVE product page: computed background is #2e7d32', product.look?.bg === 'rgb(46, 125, 50)', product.look?.bg);
    check('LIVE product page: computed text colour is white', product.look?.color === 'rgb(255, 255, 255)', product.look?.color);
    check('LIVE product page: computed padding is 6px 10px', product.look?.padding === '6px 10px', product.look?.padding);
    check('LIVE product page: computed corner radius is 4px', product.look?.radius === '4px', product.look?.radius);
    check('LIVE product page: the text is centred', product.look?.align === 'center', product.look?.align);
    check('LIVE footer: the block is there with the same background', home.look !== null && home.look.bg === 'rgb(46, 125, 50)', JSON.stringify(home.look));
    check('the served HTML has no style= attribute (product page and home page)', ! / style=/i.test(product.raw) && ! / style=/i.test(home.raw));
    check('the token stylesheet carries the rule of the block', rule.test(product.css), product.css.slice(0, 200));
    await shot('live-before-restyle', page.locator('body').first()).catch(() => {});

    // ---- change the colour in the form: the live page follows with no other click ----------------------------------------------------------------------
    await page.goto(BASE + `/admin/html-blocks/${id}/edit`, { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    await setText('bg', '#1b5e20');
    await page.locator('button[type=submit]').filter({ hasText: /Запази|Save/ }).first().click();
    await settle();
    const changed = await live('/product/' + slug, '.sf-summary');
    check('after changing the colour in the form the LIVE page shows the new colour with no other click', changed.look?.bg === 'rgb(27, 94, 32)', changed.look?.bg);
    check('…and the token stylesheet has the new rule only', /background:#1b5e20/.test(changed.css) && ! /background:#2e7d32/.test(changed.css));
    const footerChanged = await live('/', '.sf-footer');
    check('…and the footer follows too', footerChanged.look?.bg === 'rgb(27, 94, 32)', footerChanged.look?.bg);

    // ---- «Без стил» ----------------------------------------------------------------------------------------------------------------------------------------------
    await page.getByRole('button', { name: 'Без стил' }).click();
    await sleep(900);
    await page.locator('button[type=submit]').filter({ hasText: /Запази|Save/ }).first().click();
    await settle();
    const cleared = await live('/product/' + slug, '.sf-summary');
    const clearedHome = await live('/', '.sf-footer');
    check('«Без стил»: the block has no wrapper on the product page any more', cleared.look === null && ! cleared.raw.includes('sf-hb-' + id), JSON.stringify(cleared.look));
    check('«Без стил»: the footer has no wrapper either', clearedHome.look === null && ! clearedHome.raw.includes('sf-hb-' + id));
    check('«Без стил»: the rule is gone from the token stylesheet', ! cleared.css.includes('sf-hb-' + id), cleared.css.slice(0, 200));
    check('«Без стил»: the stored style is null', blockStyle() === '' || blockStyle() === 'null', blockStyle());
    check('the block\'s text is still printed (no wrapper, same HTML)', cleared.raw.includes(BLOCK_TITLE));

    console.error('known /api/cart 500s from the preview frame: ' + cartApi500.length);
    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        try {
            const report = JSON.parse(php(RESTORE));
            console.error('restored: ' + JSON.stringify(report));
            check('the settings table is byte-for-byte what it was (a checksum of every row)', report.sum === report.was, JSON.stringify(report));
            check('the throw-away admin and the block are removed, the other blocks are as they were, the appearance/ files are the ones that were there', report.staff === 0 && report.madeBlocks === 0 && report.blocksSame === true && report.appearanceFiles === report.appearanceWas, JSON.stringify(report));
        } catch (e) {
            check('the restore finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors, no 5xx (the known /api/cart one aside)', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
