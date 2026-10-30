// A REAL-BROWSER run of HB-3: the «Параграф» button of the HTML block editor and the paragraphs it makes. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed. It creates a
// throw-away admin («hb3-audit») and two blocks through the real form, BACKS UP the `site_settings` table, the `appearance/` files and the `html_blocks` style/conditions first; at the end it restores them, removes the admin
// and the blocks it made, and verifies a checksum of `site_settings`. Prints a JSON report; exit code 1 when a check failed.
//
// Steps: (1) block «HB3 Промо»: a heading, «Параграф», a sentence → the stored body is `<h2>…</h2><p>…</p>`; (2) block «HB3 Обикновен»: a heading, then «Параграф» while the cursor is in the heading → the stored body
// is `<p>…</p>` only; (3) the first block goes into the right column (Design → Приложи → Публикувай) → the live product page prints the heading then the paragraph, paragraphs have a gap between them and none
// after the last one, and the served HTML has no `style=`; (4) screenshots of the editor toolbar and of the live block.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-3.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'hb3-audit@example.test';
const PASSWORD = 'Hb3-' + randomBytes(6).toString('hex');
const PROMO_TITLE = 'HB3 Промо';
const PLAIN_TITLE = 'HB3 Обикновен';
const HEADING = 'Промокод Есен -10%';
const SENTENCE = 'Важи до края на месеца.';
const SECOND = 'Един код на поръчка.';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-3.mjs');
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
$blocks = DB::table('html_blocks')->orderBy('id')->get(['id', 'style', 'conditions'])->map(fn ($r) => (array) $r)->all();
file_put_contents(sys_get_temp_dir().'/hb3_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'blocks' => $blocks, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
DB::table('html_blocks')->whereIn('title',['${PROMO_TITLE}','${PLAIN_TITLE}'])->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HB3 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'blocks' => count($blocks), 'sum' => md5(json_encode($rows)), 'slug' => DB::table('catalog_products')->where('status', 'active')->where('catalog_visibility', 'visible')->orderBy('id')->value('slug')]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb3_backup.json'), true);
DB::table('html_blocks')->whereIn('title',['${PROMO_TITLE}','${PLAIN_TITLE}'])->delete();
foreach ($bak['blocks'] as $b) { DB::table('html_blocks')->where('id', $b['id'])->update(['style' => $b['style'], 'conditions' => $b['conditions']]); }
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'madeBlocks' => DB::table('html_blocks')->whereIn('title',['${PROMO_TITLE}','${PLAIN_TITLE}'])->count()]);`;

const sub = (code) => code.replaceAll('${EMAIL}', EMAIL).replaceAll('${PASSWORD}', PASSWORD).replaceAll('${PROMO_TITLE}', PROMO_TITLE).replaceAll('${PLAIN_TITLE}', PLAIN_TITLE);

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
    console.error('backup: ' + JSON.stringify({ rows: setup.rows, blocks: setup.blocks, slug: setup.slug }));
    check('the dev shop has a product to show the block on', ! ! setup.slug, setup.slug);

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1300 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => diag.push('PAGEERROR ' + String(e.message || e).slice(0, 200)));
    page.on('response', (r) => { if (r.status() >= 500 && ! r.url().endsWith('/api/cart')) { diag.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); } });
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };
    const toolbar = () => page.locator('.fi-header-actions-ctn .fi-btn');
    const confirmModal = async () => { await sleep(700); if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };
    const saveForm = async (name) => { await page.locator('button[type=submit]').filter({ hasText: name }).first().click(); await settle(); };
    const bodyOf = (title) => php(`echo (string) DB::table('html_blocks')->where('title','${title}')->value('body');`);
    const idOf = (title) => php(`echo (string) DB::table('html_blocks')->where('title','${title}')->value('id');`);
    // the editor's toolbar button by its Bulgarian name (the paragraph button is ours, HB-3)
    const tool = (name) => page.getByRole('button', { name, exact: true }).first();

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    // ---- (1) heading, «Параграф», a sentence ---------------------------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    const paragraphButton = await tool('Параграф').count();
    check('the editor toolbar has the «Параграф» button', paragraphButton === 1, String(paragraphButton));
    await page.fill('input[id="form.title"]', PROMO_TITLE);
    await page.locator('.tiptap').first().click();
    await tool('Подзаглавие').click();
    await page.keyboard.type(HEADING);
    await page.keyboard.press('Enter');
    await tool('Параграф').click();
    await page.keyboard.type(SENTENCE);
    await page.keyboard.press('Enter');
    await page.keyboard.type(SECOND);
    await shot(page, 'hb3-editor-toolbar', page.locator('.fi-fo-rich-editor').first());
    await saveForm(/Създай|Create/);
    const promoBody = bodyOf(PROMO_TITLE);
    check('a heading then two paragraphs are saved as written', promoBody === `<h2>${HEADING}</h2><p>${SENTENCE}</p><p>${SECOND}</p>`, promoBody);

    // ---- (2) «Параграф» inside a heading turns it into plain text ----------------------------------------------------------------------------------------
    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    await page.waitForSelector('input[id="form.title"]', { timeout: 30000 });
    await page.fill('input[id="form.title"]', PLAIN_TITLE);
    await page.locator('.tiptap').first().click();
    await tool('Подзаглавие').click();
    await page.keyboard.type(HEADING);
    await tool('Параграф').click();
    await sleep(400);
    const headingsLeft = await page.locator('.tiptap h2').count();
    check('«Параграф» inside a heading turns it into plain text in the editor', headingsLeft === 0, String(headingsLeft));
    await saveForm(/Създай|Create/);
    const plainBody = bodyOf(PLAIN_TITLE);
    check('the saved body is a paragraph and no heading', plainBody === `<p>${HEADING}</p>`, plainBody);

    // ---- (3) the first block into the right column ----------------------------------------------------------------------------------------------------------
    const promoId = idOf(PROMO_TITLE);
    await page.goto(BASE + '/admin/design?zone=summary', { waitUntil: 'networkidle' });
    await page.waitForSelector('.ds', { timeout: 30000 });
    await sleep(900);
    await toolbar().filter({ hasText: 'Върни публикуваното' }).first().click();
    await confirmModal();
    await page.locator('.ds-palette-chips .ds-chip-add', { hasText: 'HTML блок' }).click();
    await settle();
    await page.locator('.ds-chips-list > li[data-ds-component="html_block"] .ds-chip-main').last().click();
    await sleep(700);
    await page.locator('.ds-props .fi-select-input, .ds-props .fi-fo-select button, .ds-props [role=combobox]').first().click();
    await sleep(500);
    await page.getByRole('option', { name: PROMO_TITLE }).click();
    await sleep(500);
    await page.locator('.ds-props button[type=submit]').click();
    await settle();
    await toolbar().filter({ hasText: 'Публикувай' }).first().click();
    await confirmModal();
    await sleep(2500);

    const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const served = await (await ctx.request.get(BASE + '/product/' + setup.slug)).text();
    const live = await ctx.newPage();
    live.on('pageerror', (e) => diag.push('LIVE PAGEERROR ' + String(e.message || e).slice(0, 200)));
    await live.goto(BASE + '/product/' + setup.slug, { waitUntil: 'networkidle' });
    // the shop may already have other blocks in its right column: this one is the box that holds the heading we typed
    const block = await live.evaluate((heading) => {
        const box = [...document.querySelectorAll('.sf-summary .sf-summary-html')].find((e) => e.textContent.includes(heading));
        if (! box) return null;
        const kids = [...box.children].map((e) => ({ tag: e.tagName.toLowerCase(), text: e.textContent.trim(), mb: getComputedStyle(e).marginBottom, mt: getComputedStyle(e).marginTop }));
        return { html: box.innerHTML, kids };
    }, HEADING);
    check('the right column prints the block: a heading, then two paragraphs', block !== null && block.kids.map((k) => k.tag).join(',') === 'h2,p,p', JSON.stringify(block?.kids));
    // the rule is 0.5em of the 16px body text: 8px between the two paragraphs, 0 after the last one
    check('the first paragraph has an 8px gap after it, the last one none', block && block.kids[1].mb === '8px' && block.kids[2].mb === '0px', JSON.stringify(block?.kids));
    check('the served page carries no style attribute in the block', ! /<div class="sf-summary-html"[^>]*style=/.test(served) && ! /<(h2|p)[^>]* style=/.test(served));
    await shot(live, 'hb3-live-block', live.locator('.sf-summary .sf-summary-html', { hasText: HEADING }));
    await ctx.close();

    // ---- (4) the stored block is what was typed ----------------------------------------------------------------------------------------------------------------
    check('the block on the page is the saved one', block && block.html === `<h2>${HEADING}</h2><p>${SENTENCE}</p><p>${SECOND}</p>`, block && block.html);
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(sub(RESTORE)));
        check('restored: site_settings is byte-for-byte what it was', restored.sum === restored.was, JSON.stringify(restored));
        check('restored: the throw-away admin and both blocks are gone', restored.staff === 0 && restored.madeBlocks === 0, JSON.stringify(restored));
    }
}

check('no page errors or server errors', diag.length === 0, diag.slice(0, 5).join(' | '));
const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);

async function shot(page, name, locator) {
    if (! OUT) return;
    const path = join(OUT, name + '.png');
    shots.push(path);
    await (locator ?? page).screenshot({ path });
}
