// A REAL-BROWSER run of SC-1: the «Дясна колона» zone of the «Дизайн» screen (the product page's right column as an ordered list of components). NOT part of `php artisan test`: run by hand against a
// dev site whose data may be changed. It creates a throw-away admin («sc1-audit») and ONE throw-away HTML block, and BACKS UP the `site_settings` table and the `appearance/` files (with their
// contents) first; at the end it restores them, removes the admin and the block, and verifies the settings table is byte-for-byte what it was (a checksum of every row). Prints a JSON report; exit
// code 1 when a check failed.
//
// Steps: open the zone → the toolbar is the model's and the list is today's order → move «Цена» above «Име» → add an «Арт. №» and an «HTML блок» (and choose the block) → «Приложи» → the preview
// frame shows the DRAFT order while the LIVE product page is unchanged → try to remove «Покупка» (refused, still there) → «Публикувай» → the live page shows the new order, the SKU and the block →
// «Начални настройки» → «Публикувай» → the live right column is byte-for-byte what it was at the start.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/sc-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'sc1-audit@example.test';
const PASSWORD = 'Sc1-' + randomBytes(6).toString('hex');
const BLOCK_TITLE = 'sc1-audit-block';
const BLOCK_TEXT = 'SC1 club note -20% with code BDAY';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node sc-1.mjs');
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
file_put_contents(sys_get_temp_dir().'/sc1_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->delete();
App\Content\HtmlBlocks\HtmlBlockModel::create(['title' => '${BLOCK_TITLE}', 'body' => '<p>${BLOCK_TEXT}</p>']);
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'SC1 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/sc1_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'blocks' => DB::table('html_blocks')->where('title','${BLOCK_TITLE}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const setting = (key) => php(`$v = DB::table('site_settings')->where('key','${key}')->value('value'); echo $v === null ? '__NULL__' : md5($v);`);
// the first visible product of the shop (the one the preview shows) and its SKU
const firstProduct = () => php(`$s = app(App\\Storefront\\Reader\\CatalogReader::class)->listing(App\\Storefront\\ReadModels\\ListingQuery::fromRequestArray([]))->items[0] ?? null; echo $s === null ? '' : $s->slug;`);
const skuOf = (slug) => php(`echo (string) DB::table('catalog_products')->where('slug','${slug}')->value('base_sku');`);

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
    const sku = skuOf(slug);
    check('the shop has a visible product to look at', slug !== '', slug);

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1200 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    // `/api/cart` answers 500 («Session store not set on request», CartController::findCurrentCart) whenever a storefront page loads inside the admin session (the preview frame): an older, separate problem
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
    const stateText = async () => (await page.locator('.ds-state span:visible').allInnerTexts()).map((t) => t.trim()).join(' | ');
    const publishDisabled = async () => (await button('Публикувай').getAttribute('disabled')) !== null || (await button('Публикувай').getAttribute('class')).includes('fi-disabled');
    /** the components of the zone, in order (the `data-ds-component` of every chip) */
    const listed = async () => page.locator('.ds-chips-list > li').evaluateAll((els) => els.map((e) => e.getAttribute('data-ds-component')));
    const chip = (type) => page.locator(`.ds-chips-list > li[data-ds-component="${type}"] .ds-chip-main`);

    /** a fresh context's view of the live product page: the components of the right column in order, and the column's HTML */
    const live = async () => {
        const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const p = await ctx.newPage();
        await p.goto(BASE + '/product/' + slug, { waitUntil: 'networkidle' });
        const out = await p.evaluate(() => {
            const column = document.querySelector('.sf-summary');
            const order = [...column.children].map((c) => (c.className.match(/sf-(product-brand|title|short-description|price|stock|sku|summary-html|add-to-cart-box|variations)\b/) || [null, c.tagName.toLowerCase()])[1]);

            return { order, html: column.outerHTML, text: column.innerText };
        });
        await p.locator('.sf-product-main').screenshot({ path: OUT ? join(OUT, 'live-' + (shots.length) + '.png') : undefined }).then(() => OUT && shots.push(join(OUT, 'live-' + (shots.length) + '.png'))).catch(() => {});
        await ctx.close();

        return out;
    };

    const before = await live();
    console.error('live order before: ' + before.order.join(' > '));

    await page.goto(BASE + '/admin/design?zone=summary', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    // the dev site may hold a draft: start from the published version
    await button('Върни публикуваното').click();
    await confirmModal();

    // ---- the toolbar, the list, the panel -------------------------------------------------------------------------------------------------
    const texts = (await toolbar().allInnerTexts()).map((t) => t.trim());
    check('the toolbar is Към админа · Върни публикуваното · Начални настройки · Публикувай', JSON.stringify(texts) === JSON.stringify(['Към админа', 'Върни публикуваното', 'Начални настройки', 'Публикувай']), JSON.stringify(texts));
    check('«Публикувай» is the last button and red', (await toolbar().last().innerText()).trim() === 'Публикувай' && (await toolbar().last().getAttribute('class')).includes('fi-color-danger'));
    const submits = await page.locator('.ds-props button[type=submit]').allInnerTexts();
    check('the panel has ONE button, «Приложи»', submits.length === 1 && submits[0].trim() === 'Приложи', JSON.stringify(submits));
    check('nothing to publish at first and the indicator says «Публикувано»', (await publishDisabled()) && (await stateText()).includes('Публикувано'), await stateText());
    const start = await listed();
    check('the list is today\'s order: Марка, Име, Кратко описание, Цена, Наличност, Покупка', JSON.stringify(start) === JSON.stringify(['brand', 'title', 'short_description', 'price', 'stock', 'purchase']), JSON.stringify(start));
    const offered = await page.locator('.ds-palette-chips .ds-chip-add').allInnerTexts();
    check('the palette offers only what can still be added: Арт. № and HTML блок', JSON.stringify(offered.map((t) => t.trim())) === JSON.stringify(['Арт. №', 'HTML блок']), JSON.stringify(offered));
    await shot('summary-zone-before', page.locator('.ds-main'));

    // ---- move «Цена» above «Име» ----------------------------------------------------------------------------------------------------------
    await chip('price').click();
    await sleep(500);
    for (let i = 0; i < 2; i++) {
        await page.locator('.ds-chips-list > li.ds-sel [aria-label="Премести нагоре"]').click();
        await settle();
    }
    const moved = await listed();
    check('«Цена» is above «Име» in the list', moved.indexOf('price') < moved.indexOf('title'), JSON.stringify(moved));
    check('the move is stored as the draft at once', (await setting('design.product_summary.global.draft')) !== '__NULL__');

    // ---- add «Арт. №» and an «HTML блок» -----------------------------------------------------------------------------------------------------
    await page.locator('.ds-palette-chips .ds-chip-add', { hasText: 'Арт. №' }).click();
    await settle();
    await page.locator('.ds-palette-chips .ds-chip-add', { hasText: 'HTML блок' }).click();
    await settle();
    check('«Арт. №» and «HTML блок» are in the list', (await listed()).includes('sku') && (await listed()).includes('html_block'), JSON.stringify(await listed()));
    check('the palette no longer offers «Арт. №»', ! (await page.locator('.ds-palette-chips').innerText()).includes('Арт. №'));

    // the new HTML block needs its block chosen: it is the selected component, the panel shows the picker
    await chip('html_block').click();
    await sleep(700);
    await page.locator('.ds-props .fi-select-input, .ds-props .fi-fo-select button, .ds-props [role=combobox]').first().click();
    await sleep(500);
    await page.getByRole('option', { name: BLOCK_TITLE }).click();
    await sleep(500);
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    check('«Приложи»: the indicator says draft and «Публикувай» is enabled', (await stateText()).includes('Чернова — не е публикувана') && ! (await publishDisabled()), await stateText());
    check('after «Приложи» the LIVE product page is unchanged', JSON.stringify((await live()).order) === JSON.stringify(before.order));

    // ---- the preview shows the draft ----------------------------------------------------------------------------------------------------------
    await page.locator('iframe[title^="Преглед на продуктова страница"]').scrollIntoViewIfNeeded();
    await sleep(3500);
    const frame = page.frameLocator('iframe[title^="Преглед на продуктова страница"]');
    const previewOrder = await frame.locator('.sf-summary').evaluate((column) => [...column.children].map((c) => (c.className.match(/sf-(product-brand|title|short-description|price|stock|sku|summary-html|add-to-cart-box|variations)\b/) || [null, c.tagName.toLowerCase()])[1]));
    check('the preview frame shows the DRAFT order (price before title, the SKU and the block in)', previewOrder.indexOf('price') < previewOrder.indexOf('title') && previewOrder.includes('sku') && previewOrder.includes('summary-html'), previewOrder.join(' > '));
    check('the preview frame prints the HTML block\'s text', (await frame.locator('.sf-summary').innerText()).includes(BLOCK_TEXT));
    await shot('summary-zone-draft', page.locator('.ds-main'));

    // ---- «Покупка» cannot be removed ------------------------------------------------------------------------------------------------------------
    await chip('purchase').click();
    await sleep(500);
    await page.locator('.ds-chips-list > li.ds-sel [aria-label="Премести нагоре"]').count();
    await page.locator('.ds-chips-list > li.ds-sel [aria-label="Премахни"]').click();
    await settle();
    check('removing «Покупка» is refused: it is still in the list', (await listed()).includes('purchase'), JSON.stringify(await listed()));
    check('…and the refusal says why', (await page.locator('.fi-no').allInnerTexts()).join(' ').includes('Този компонент е задължителен и не може да се премахне.'), (await page.locator('.fi-no').allInnerTexts()).join(' '));

    // ---- «Публикувай» -----------------------------------------------------------------------------------------------------------------------------
    await button('Публикувай').click();
    await confirmModal();
    await sleep(2500);
    check('after «Публикувай»: «Публикувано» and the button disabled again', (await stateText()).includes('Публикувано') && await publishDisabled(), await stateText());
    check('the draft is forgotten and the published layout is stored', (await setting('design.product_summary.global.draft')) === '__NULL__' && (await setting('design.product_summary.global.published')) !== '__NULL__');
    const after = await live();
    check('the LIVE page shows «Цена» above «Име»', after.order.indexOf('price') < after.order.indexOf('title'), after.order.join(' > '));
    check('the LIVE page shows the SKU' + (sku === '' ? ' (the product has none: nothing printed)' : ''), sku === '' ? ! after.order.includes('sku') : after.order.includes('sku') && after.text.includes(sku), after.order.join(' > '));
    check('the LIVE page shows the HTML block', after.order.includes('summary-html') && after.text.includes(BLOCK_TEXT));
    check('the add-to-cart box is still on the page', after.order.includes('add-to-cart-box'));
    await shot('summary-zone-published', page.locator('.ds-main'));

    // ---- «Начални настройки» → «Публикувай»: the page is today's again -----------------------------------------------------------------------------
    await button('Начални настройки').click();
    await confirmModal();
    const reset = await listed();
    check('«Начални настройки»: the working copy is today\'s order', JSON.stringify(reset) === JSON.stringify(['brand', 'title', 'short_description', 'price', 'stock', 'purchase']), JSON.stringify(reset));
    check('…and the LIVE page is still the published (changed) one until «Публикувай»', JSON.stringify((await live()).order) === JSON.stringify(after.order));
    await button('Публикувай').click();
    await confirmModal();
    await sleep(2500);
    const restored = await live();
    check('after «Публикувай» the live right column is byte-for-byte the one at the start', restored.html === before.html, restored.order.join(' > '));
    check('the settings table has no key of the zone again', (await setting('design.product_summary.global.published')) === '__NULL__' && (await setting('design.product_summary.global.draft')) === '__NULL__');

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
            check('the throw-away admin and block are removed and the appearance/ files are the ones that were there', report.staff === 0 && report.blocks === 0 && report.appearanceFiles === report.appearanceWas, JSON.stringify(report));
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
