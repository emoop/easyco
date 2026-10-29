// A REAL-BROWSER run of HB-2: «Показвай, когато…» of an HTML block. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed (the migrations of HB-1 and HB-2 must have run).
// It creates a throw-away admin («hb2-audit») and TWO blocks through the real form, BACKS UP the `site_settings` table, the `appearance/` files (with their contents) and the `style` / `conditions` of every
// existing HTML block first; at the end it restores them, removes the admin and the blocks it made, and verifies the settings table is byte-for-byte what it was (a checksum of every row). It touches no
// product. Prints a JSON report; exit code 1 when a check failed.
//
// Steps: create «Допълнителна отстъпка -10%» (shown only when «Промоция» = «Само промоционални») and «Безплатна доставка» (no conditions) → put both in the right column (Design → «Дясна колона» → HTML блок
// → Приложи → Публикувай) → a product on sale shows both, a product that is not on sale shows only the second → the condition becomes a category → the live pages follow with no other click → the form
// (two sections side by side, closed while empty, open when set; the page heights are printed) → the footer shows a block with conditions anyway.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-2.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'hb2-audit@example.test';
const PASSWORD = 'Hb2-' + randomBytes(6).toString('hex');
const SALE_TITLE = 'Допълнителна отстъпка -10%';
const ALL_TITLE = 'Безплатна доставка';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-2.mjs');
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
$blocks = DB::table('html_blocks')->orderBy('id')->get(['id', 'style', 'conditions'])->map(fn ($r) => (array) $r)->all();
file_put_contents(sys_get_temp_dir().'/hb2_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'blocks' => $blocks, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
DB::table('html_blocks')->whereIn('title',['${SALE_TITLE}','${ALL_TITLE}'])->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HB2 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'blocks' => count($blocks), 'sum' => md5(json_encode($rows))]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb2_backup.json'), true);
DB::table('html_blocks')->whereIn('title',['${SALE_TITLE}','${ALL_TITLE}'])->delete();
foreach ($bak['blocks'] as $b) { DB::table('html_blocks')->where('id', $b['id'])->update(['style' => $b['style'], 'conditions' => $b['conditions']]); }
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$blocks = DB::table('html_blocks')->orderBy('id')->get(['id', 'style', 'conditions'])->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'madeBlocks' => DB::table('html_blocks')->whereIn('title',['${SALE_TITLE}','${ALL_TITLE}'])->count(), 'blocksSame' => md5(json_encode($blocks)) === md5(json_encode($bak['blocks'])), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

// a product on sale, one that is not, and a category of the first that the second is not in (read through the storefront's own reader: «on sale» is what the price prints)
const PICK = String.raw`
$r = app(App\Storefront\Reader\CatalogReader::class); $sale = null; $plain = null;
foreach ($r->listing(App\Storefront\ReadModels\ListingQuery::fromRequestArray([]))->items as $i) { $p = $r->product($i->slug); if ($p === null) continue; $on = $p->price?->regularFromMinor !== null; if ($on && ! $sale) $sale = $p; if (! $on && ! $plain) $plain = $p; }
$cats = fn ($p) => $p === null ? [] : DB::table('catalog_product_categories')->where('product_id', $p->id)->pluck('category_id')->all();
$only = array_values(array_diff($cats($sale), $cats($plain)));
echo json_encode(['sale' => $sale?->slug, 'plain' => $plain?->slug, 'category' => $only[0] ?? null, 'categoryName' => isset($only[0]) ? DB::table('catalog_categories')->where('id', $only[0])->value('name') : null]);`;

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
    const pick = JSON.parse(php(PICK));
    console.error('pick: ' + JSON.stringify(pick));
    check('the dev shop has a product on sale, one that is not, and a category that tells them apart', pick.sale && pick.plain && pick.category, JSON.stringify(pick));

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1300 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    // `/api/cart` answers 500 («Session store not set on request») whenever a storefront page loads inside the admin session (the preview frame): an older, separate problem
    const cartApi500 = [];
    page.on('response', (r) => { if (r.status() >= 500) { r.url().endsWith('/api/cart') ? cartApi500.push(r.url()) : consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); } });
    const shot = async (name, locator) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await locator.screenshot({ path }); };
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
    const height = () => page.evaluate(() => document.documentElement.scrollHeight);

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    const toolbar = () => page.locator('.fi-header-actions-ctn .fi-btn');
    const button = (name) => toolbar().filter({ hasText: name }).first();
    const confirmModal = async () => { await sleep(700); if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };

    /** a fresh context's view of a live product page: the served HTML, how many HTML blocks the right column has, and its text */
    const live = async (slug) => {
        const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
        const raw = await (await ctx.request.get(BASE + '/product/' + slug)).text();
        const p = await ctx.newPage();
        await p.goto(BASE + '/product/' + slug, { waitUntil: 'networkidle' });
        const column = await p.evaluate(() => { const c = document.querySelector('.sf-summary'); return { blocks: c.querySelectorAll('.sf-summary-html').length, empty: [...c.querySelectorAll('.sf-summary-html')].filter((e) => e.innerText.trim() === '').length, text: c.innerText }; });
        OUT && await p.locator('.sf-summary').first().screenshot({ path: join(OUT, 'live-' + slug.replace(/[^a-z0-9-]/gi, '').slice(0, 20) + '-' + shots.length + '.png') }).then(() => shots.push('live-' + slug)).catch(() => {});
        await ctx.close();

        return { raw, ...column };
    };

    // a Filament select (custom): open it from its label and choose an option by its text
    const choose = async (label, option, search = false) => {
        await page.locator('.fi-fo-field', { hasText: label }).first().locator('button, [role=combobox]').first().click();
        await sleep(500);
        // a searchable select: type the name first (the list is the whole tree, the option is «Родител › Категория»)
        search && await page.keyboard.type(option);
        search && await sleep(800);
        await page.getByRole('option', { name: option }).first().click();
        await sleep(700);
        await page.keyboard.press('Escape');
        await sleep(300);
    };
    const saveForm = async (name) => { await page.locator('button[type=submit]').filter({ hasText: name }).first().click(); await settle(); };
    const blockId = (title) => php(`echo (string) DB::table('html_blocks')->where('title','${title}')->value('id');`);
    const conditionsOf = (title) => php(`echo (string) DB::table('html_blocks')->where('title','${title}')->value('conditions');`);

    // ---- the form: the compact layout --------------------------------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    const closedHeight = await height();
    const sections = page.locator('.fi-hb-compact section.fi-section');
    const boxes = await sections.evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), title: e.querySelector('h2')?.innerText.trim() }; }));
    check('two sections «Стил на блока» and «Показвай, когато…» are side by side (the same top, two columns)', boxes.length === 2 && boxes[0].title === 'Стил на блока' && boxes[1].title === 'Показвай, когато…' && Math.abs(boxes[0].y - boxes[1].y) <= 2 && boxes[1].x > boxes[0].x + boxes[0].w - 4, JSON.stringify(boxes));
    check('both are closed while the block is empty', (await page.locator('.fi-hb-compact section.fi-collapsed').count()) === 2, String(await page.locator('.fi-hb-compact section.fi-collapsed').count()));
    console.error('PAGE HEIGHT create form, both sections closed: ' + closedHeight + 'px');

    await page.fill('input[id="form.title"]', SALE_TITLE);
    await page.locator('.tiptap').first().click();
    await page.keyboard.type(SALE_TITLE);
    await page.locator('.fi-hb-compact section', { hasText: 'Показвай, когато…' }).first().locator('header').click();
    await sleep(700);
    await choose('Промоция', 'Само промоционални');
    check('the scope line is under the conditions', (await page.locator('.fi-hb-compact').innerText()).includes('Важи само в дясната колона на продукта.'));
    await saveForm(/Създай|Create/);
    const saleId = blockId(SALE_TITLE);
    check('the block «' + SALE_TITLE + '» is saved with the sale condition', saleId !== '' && conditionsOf(SALE_TITLE).replace(/\s/g, '') === '{"on_sale":true}', conditionsOf(SALE_TITLE));

    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    await page.fill('input[id="form.title"]', ALL_TITLE);
    await page.locator('.tiptap').first().click();
    await page.keyboard.type(ALL_TITLE);
    await saveForm(/Създай|Create/);
    check('the block «' + ALL_TITLE + '» is saved with no conditions', blockId(ALL_TITLE) !== '' && conditionsOf(ALL_TITLE) === '', conditionsOf(ALL_TITLE));

    // ---- both into the right column --------------------------------------------------------------------------------------------------------------------
    const chooseBlock = async (title) => {
        await page.locator('.ds-props .fi-select-input, .ds-props .fi-fo-select button, .ds-props [role=combobox]').first().click();
        await sleep(500);
        await page.getByRole('option', { name: title }).click();
        await sleep(500);
    };
    await page.goto(BASE + '/admin/design?zone=summary', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await button('Върни публикуваното').click();
    await confirmModal();
    for (const title of [SALE_TITLE, ALL_TITLE]) {
        await page.locator('.ds-palette-chips .ds-chip-add', { hasText: 'HTML блок' }).click();
        await settle();
        await page.locator('.ds-chips-list > li[data-ds-component="html_block"] .ds-chip-main').last().click();
        await sleep(700);
        await chooseBlock(title);
        await page.locator('.ds-props button[type=submit]').click();
        await settle();
    }
    await button('Публикувай').click();
    await confirmModal();
    await sleep(2500);

    // ---- the live pages ------------------------------------------------------------------------------------------------------------------------------------------
    const onSale = await live(pick.sale);
    const notOnSale = await live(pick.plain);
    check('a product ON SALE shows both blocks', onSale.text.includes(SALE_TITLE) && onSale.text.includes(ALL_TITLE), onSale.text.slice(-200));
    check('a product that is NOT on sale shows only «' + ALL_TITLE + '»', ! notOnSale.text.includes(SALE_TITLE) && notOnSale.text.includes(ALL_TITLE), notOnSale.text.slice(-200));
    check('the hidden block leaves no empty wrapper behind', notOnSale.empty === 0 && onSale.empty === 0, `${notOnSale.empty}/${onSale.empty}`);
    check('the served HTML has no style= attribute', ! / style=/i.test(onSale.raw) && ! / style=/i.test(notOnSale.raw));

    // ---- the condition becomes a category: the pages follow with no other click -------------------------------------------------------------------------------------
    await page.goto(BASE + `/admin/html-blocks/${saleId}/edit`, { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    check('the form opens with the conditions section OPEN (it holds a value)', (await page.locator('.fi-hb-compact section.fi-collapsed').count()) === 1, String(await page.locator('.fi-hb-compact section.fi-collapsed').count()));
    const openHeight = await height();
    await choose('Промоция', 'Всички');
    await choose('Категории', pick.categoryName, true);
    await shot('form-conditions', page.locator('.fi-hb-compact'));
    await saveForm(/Запази|Save/);
    check('the stored condition is now the category', conditionsOf(SALE_TITLE).replace(/\s/g, '') === `{"categories":[${pick.category}]}`, conditionsOf(SALE_TITLE));
    const inCategory = await live(pick.sale);
    const outOfCategory = await live(pick.plain);
    check('after the edit the product IN the category shows the block with no other click', inCategory.text.includes(SALE_TITLE), inCategory.text.slice(-160));
    check('…and the product outside it does not', ! outOfCategory.text.includes(SALE_TITLE), outOfCategory.text.slice(-160));
    check('…«' + ALL_TITLE + '» is still on both', inCategory.text.includes(ALL_TITLE) && outOfCategory.text.includes(ALL_TITLE));

    // the style section opens too when the block has a style: both open, side by side
    await page.goto(BASE + `/admin/html-blocks/${saleId}/edit`, { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    await page.locator('.fi-hb-compact section', { hasText: 'Стил на блока' }).first().locator('header').click();
    await sleep(700);
    const bothOpenHeight = await height();
    await shot('form-both-open', page.locator('.fi-hb-compact'));
    console.error(`PAGE HEIGHT edit form: both closed ${closedHeight}px, conditions open ${openHeight}px, both open ${bothOpenHeight}px (viewport 1300px)`);
    check('the whole block page fits in about a screen and a half even with both sections open', bothOpenHeight <= 2200, String(bothOpenHeight));

    // «Без условия»
    await page.getByRole('button', { name: 'Без условия' }).click();
    await sleep(900);
    await saveForm(/Запази|Save/);
    check('«Без условия» clears the conditions', conditionsOf(SALE_TITLE) === '', conditionsOf(SALE_TITLE));
    const everywhere = await live(pick.plain);
    check('with no conditions the block is on every product again', everywhere.text.includes(SALE_TITLE));

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
            check('the throw-away admin and the two blocks are removed, the other blocks are as they were, the appearance/ files are the ones that were there', report.staff === 0 && report.madeBlocks === 0 && report.blocksSame === true && report.appearanceFiles === report.appearanceWas, JSON.stringify(report));
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
