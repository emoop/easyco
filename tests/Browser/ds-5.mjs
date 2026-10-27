// A REAL-BROWSER run of DS-5: ONE button model for every zone of the «Дизайн» screen. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed.
// It creates a throw-away admin («ds5-audit») and BACKS UP the `site_settings` table and the `appearance/` files (with their contents) first; at the end it restores them, removes the admin, and
// verifies the settings table is byte-for-byte what it was (a checksum of every row). Prints a JSON report; exit code 1 when a check failed.
//
// For every zone (Топбанер, Хедър, Футър, Количка и поръчка, Страница и карти, Продуктова страница): a screenshot of the toolbar and of the panel, the buttons in order (Към админа · Върни публикуваното ·
// [Начални настройки] · Публикувай), «Публикувай» red and last, the panel's ONE button «Приложи», no «Запази». Then real clicks: type → the indicator says the panel has un-applied edits and «Публикувай» is
// disabled → «Приложи» → «Чернова — не е публикувана» and the LIVE page has not changed → «Публикувай» → the live page changed → «Начални настройки» (the working copy only) → «Публикувай» → defaults;
// «Върни публикуваното»; a structural change in the header is in the stored draft at once and survives a reload.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/ds-5.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const EMAIL = 'ds5-audit@example.test';
const PASSWORD = 'Ds5-' + randomBytes(6).toString('hex');
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node ds-5.mjs');
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
file_put_contents(sys_get_temp_dir().'/ds5_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('staff')->where('email','${EMAIL}')->delete();
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = EasyCo\Staff\Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'DS5 Audit', $role);
app(StaffRepository::class)->save($staff);
echo json_encode(['rows' => count($rows), 'sum' => md5(json_encode($rows))]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/ds5_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
DB::table('staff')->where('email','${EMAIL}')->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'rows' => count($rows), 'staff' => DB::table('staff')->where('email','${EMAIL}')->count(), 'appearanceFiles' => count(Storage::disk('public')->allFiles('appearance')), 'appearanceWas' => count($bak['files'])]);`;

const setting = (key) => php(`$v = DB::table('site_settings')->where('key','${key}')->value('value'); echo $v === null ? '__NULL__' : md5($v);`);
const productSlug = () => php(`$s = EasyCo\\Catalog\\Persistence\\Eloquent\\ProductModel::query()->where('status','active')->where('catalog_visibility','visible')->orderBy('id')->value('slug'); echo is_string($s) ? $s : '';`);

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
    const slug = productSlug();

    const admin = await browser.newContext({ viewport: { width: 1500, height: 1100 }, ignoreHTTPSErrors: true });
    const page = await admin.newPage();
    page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e.stack || e).replace(/\s+/g, ' ').slice(0, 300)));
    // `/api/cart` answers 500 («Session store not set on request», CartController::findCurrentCart) whenever a storefront page loads inside the admin session (the preview frames): a separate, older problem,
    // reported on its own and not counted as a failure of this run
    const cartApi500 = [];
    page.on('response', (r) => { if (r.status() >= 500) { r.url().endsWith('/api/cart') ? cartApi500.push(r.url()) : consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 160)); } });
    const shot = async (name, locator) => { if (! OUT) return; const path = join(OUT, name + '.png'); shots.push(path); await locator.screenshot({ path }); };
    const settle = async () => { await page.waitForLoadState('networkidle'); await sleep(900); };

    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    const open = async (zone) => { await page.goto(BASE + '/admin/design?zone=' + zone, { waitUntil: 'networkidle' }); await page.waitForSelector('.ds', { timeout: 30000 }); await sleep(900); };
    const toolbar = () => page.locator('.fi-header-actions-ctn .fi-btn');
    const toolbarTexts = async () => (await toolbar().allInnerTexts()).map((t) => t.trim());
    const button = (name) => toolbar().filter({ hasText: name }).first();
    const apply = async () => { await page.locator('.ds-props button[type=submit]').click(); await settle(); };
    const confirmModal = async () => { await sleep(700); if (await page.locator('.fi-modal-window:visible').count() > 0) { await page.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); } await settle(); };
    const publish = async () => { await button('Публикувай').click(); await confirmModal(); await sleep(2500); };
    const stateText = async () => (await page.locator('.ds-state span:visible').allInnerTexts()).map((t) => t.trim()).join(' | ');
    const publishDisabled = async () => (await button('Публикувай').getAttribute('disabled')) !== null || (await button('Публикувай').getAttribute('class')).includes('fi-disabled');
    const field = (name) => page.locator(`[id="propsForm.${name}"]`).first();

    /** a page of the shop in a fresh context (nothing cached by the admin session) */
    const live = async (url, fn, viewport = { width: 1400, height: 1000 }) => {
        const ctx = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
        const p = await ctx.newPage();
        await p.goto(BASE + url, { waitUntil: 'networkidle' });
        await sleep(300);
        const result = await fn(p);
        await ctx.close();

        return result;
    };
    const cardRadius = () => live('/', (p) => p.evaluate(() => { const c = document.querySelector('.sf-card'); return c ? getComputedStyle(c).borderTopLeftRadius : null; }));
    const homeHtml = () => live('/', (p) => p.content());
    const columnGap = () => live('/product/' + slug, (p) => p.evaluate(() => getComputedStyle(document.querySelector('.sf-product-main')).columnGap));

    // ============================================================ every zone: the same toolbar, the same panel
    const zones = [['topbar', 'Топбанер', false], ['header', 'Хедър', false], ['footer', 'Футър', true], ['checkout', 'Количка и поръчка', false], ['page', 'Страница и карти', true], ['product', 'Продуктова страница', true]];

    for (const [zone, label, hasInitial] of zones) {
        await open(zone);
        // the dev site may hold a draft the owner left: every zone starts from its published version (the backup puts everything back at the end)
        await button('Върни публикуваното').click();
        await confirmModal();
        const texts = await toolbarTexts();
        const expected = hasInitial ? ['Към админа', 'Върни публикуваното', 'Начални настройки', 'Публикувай'] : ['Към админа', 'Върни публикуваното', 'Публикувай'];
        check(`${label}: the toolbar is ${expected.join(' · ')}`, JSON.stringify(texts) === JSON.stringify(expected), JSON.stringify(texts));
        check(`${label}: «Публикувай» is red (danger) and the last button`, (await toolbar().last().innerText()).trim() === 'Публикувай' && (await toolbar().last().getAttribute('class')).includes('fi-color-danger'), await toolbar().last().getAttribute('class'));
        const submits = await page.locator('.ds-props button[type=submit]').allInnerTexts();
        check(`${label}: the panel has ONE button, «Приложи»`, submits.length === 1 && submits[0].trim() === 'Приложи', JSON.stringify(submits));
        check(`${label}: there is no «Запази» anywhere on the screen`, ! (await page.locator('.ds').innerText()).includes('Запази') && ! texts.some((t) => t.includes('Запази')), '');
        check(`${label}: nothing to publish at first («Публикувай» disabled) and the indicator says «Публикувано»`, (await publishDisabled()) && (await stateText()).includes('Публикувано') && ! (await stateText()).includes('Чернова'), await stateText());
        await shot(`${zone}-toolbar`, page.locator('.fi-header').first());
        await shot(`${zone}-panel`, page.locator('.ds-props'));
    }

    // ============================================================ Топбанер: panel edit -> apply -> live unchanged -> publish -> live changed -> discard
    await open('topbar');
    const bannerText = 'DS5 banner ' + randomBytes(3).toString('hex');
    check('the live home page has not got the new banner yet', ! (await homeHtml()).includes(bannerText));
    // the banner may be on already on the dev site: switch it ON, whatever it was
    const bannerSwitch = page.locator('.ds-props button[role=switch]').first();
    (await bannerSwitch.getAttribute('aria-checked')) !== 'true' && await bannerSwitch.click();
    await field('text').fill(bannerText);
    await sleep(500);
    check('typing in the panel: the indicator says the panel has un-applied edits', (await stateText()).includes('Има неприложени промени в панела'), await stateText());
    await apply();
    check('after «Приложи»: the indicator says «Чернова — не е публикувана» and «Публикувай» is enabled', (await stateText()).includes('Чернова — не е публикувана') && ! (await publishDisabled()), await stateText());
    check('after «Приложи» the LIVE home page is unchanged', ! (await homeHtml()).includes(bannerText));
    // the frame is `loading="lazy"`: it loads when it is scrolled into view
    await page.locator('iframe[title="Преглед на началната страница"]').scrollIntoViewIfNeeded();
    await sleep(3000);
    const previewFrame = page.frameLocator('iframe[title="Преглед на началната страница"]');
    check('the preview frame shows the DRAFT banner', (await previewFrame.locator('body').innerText()).includes(bannerText));

    await field('text').fill(bannerText + ' X');
    await sleep(500);
    check('typing again: «Публикувай» is disabled until «Приложи» (the panel has un-applied edits)', await publishDisabled());
    await field('text').fill(bannerText);
    await apply();
    await publish();
    check('after «Публикувай» the live home page shows the banner', (await homeHtml()).includes(bannerText));
    check('after «Публикувай»: «Публикувано» and «Публикувай» disabled again', (await stateText()).includes('Публикувано') && await publishDisabled(), await stateText());

    await field('text').fill('Only a draft');
    await apply();
    await button('Върни публикуваното').click();
    await confirmModal();
    check('«Върни публикуваното»: the panel holds the published banner again', (await field('text').inputValue()) === bannerText, await field('text').inputValue());

    // ============================================================ Страница и карти
    await open('page');
    const radius0 = await cardRadius();
    await field('card_radius').fill('14');
    await apply();
    check('«Страница и карти» after «Приложи»: the live card corners are unchanged', (await cardRadius()) === radius0, await cardRadius());
    check('the plain sections say under the preview that it shows the published page', (await page.locator('.ds-note-preview').innerText()).includes('Прегледът показва публикуваното'));
    await publish();
    check('after «Публикувай» the live card corners are 14px', (await cardRadius()) === '14px', await cardRadius());

    await button('Начални настройки').click();
    await confirmModal();
    check('«Начални настройки» puts the defaults into the panel (card corners 8)', (await field('card_radius').inputValue()) === '8', await field('card_radius').inputValue());
    check('…and the live card corners are STILL 14px (the working copy only)', (await cardRadius()) === '14px', await cardRadius());
    check('…the indicator says draft and «Публикувай» is enabled', (await stateText()).includes('Чернова — не е публикувана') && ! (await publishDisabled()), await stateText());
    await shot('page-after-reset', page.locator('.ds-props'));
    await publish();
    const radiusDefault = await cardRadius();
    check('after «Публикувай» the live card corners are the default again (8px)', radiusDefault === '8px', radiusDefault);

    // ============================================================ Продуктова страница
    await open('product');
    const gap0 = await columnGap();
    await field('col_gap').fill('48');
    await apply();
    check('«Продуктова страница» after «Приложи»: the live gap is unchanged', (await columnGap()) === gap0, await columnGap());
    await publish();
    check('after «Публикувай» the live gap between the gallery and the summary is 48px', (await columnGap()) === '48px', await columnGap());
    await button('Начални настройки').click();
    await confirmModal();
    check('the live gap is still 48px after «Начални настройки»', (await columnGap()) === '48px', await columnGap());
    await publish();
    check('after «Публикувай» the gap is the default 32px', (await columnGap()) === '32px', await columnGap());

    // ============================================================ Хедър: a structural change is in the stored draft at once and survives a reload
    await open('header');
    const headerPublished = await setting('header.layout.published');
    const headerDraft0 = await setting('header.layout.draft');
    const chipsBefore = await page.locator('.ds-chip').count();
    await page.locator('.ds-cell').first().locator('.ds-cell-title').click();
    await sleep(500);
    await page.locator('.ds-chip-add', { hasText: 'Разстояние' }).first().click();
    await settle();
    const headerDraft1 = await setting('header.layout.draft');
    check('Хедър: adding a component stores the draft at once (no «Приложи» needed)', headerDraft1 !== headerDraft0 && headerDraft1 !== '__NULL__', `${headerDraft0} -> ${headerDraft1}`);
    check('Хедър: the published header is untouched', (await setting('header.layout.published')) === headerPublished);
    check('Хедър: the indicator says draft', (await stateText()).includes('Чернова — не е публикувана'), await stateText());
    await page.reload({ waitUntil: 'networkidle' });
    await sleep(900);
    check('Хедър: after leaving and coming back the new component is still there', (await page.locator('.ds-chip').count()) === chipsBefore + 1, `${chipsBefore} -> ${await page.locator('.ds-chip').count()}`);
    await button('Върни публикуваното').click();
    await confirmModal();
    check('Хедър: «Върни публикуваното» removes it again', (await page.locator('.ds-chip').count()) === chipsBefore, await page.locator('.ds-chip').count());
    check('Хедър: after «Върни публикуваното» the draft is forgotten and the indicator says «Публикувано»', (await setting('header.layout.draft')) === '__NULL__' && (await stateText()).includes('Публикувано'), `${await setting('header.layout.draft')} ${await stateText()}`);

    // the same structural change, now published: the draft becomes the published header
    await page.locator('.ds-cell').first().locator('.ds-cell-title').click();
    await sleep(500);
    await page.locator('.ds-chip-add', { hasText: 'Разстояние' }).first().click();
    await settle();
    await publish();
    await settle();
    check('Хедър: «Публикувай» makes the draft the published header and forgets the draft', (await setting('header.layout.published')) !== headerPublished && (await setting('header.layout.draft')) === '__NULL__' && (await stateText()).includes('Публикувано') && await publishDisabled(), `${await setting('header.layout.published')} ${await stateText()} :: ${(await page.locator('.fi-no').allInnerTexts()).join(' / ')} :: draft=${await setting('header.layout.draft')} published0=${headerPublished}`);

    // ============================================================ Количка и поръчка: apply → draft only → publish → published
    await open('checkout');
    const checkoutPublished = await setting('design.checkout_header.global.published');
    await field('logo_height_d').fill('44');
    await apply();
    check('Количка и поръчка: «Приложи» writes the draft and not the published version', (await setting('design.checkout_header.global.draft')) !== '__NULL__' && (await setting('design.checkout_header.global.published')) === checkoutPublished);
    check('Количка и поръчка: the indicator says draft and «Публикувай» is enabled', (await stateText()).includes('Чернова — не е публикувана') && ! (await publishDisabled()), await stateText());
    await publish();
    await settle();
    check('Количка и поръчка: «Публикувай» publishes it and forgets the draft', (await setting('design.checkout_header.global.published')) !== checkoutPublished && [(await setting('design.checkout_header.global.published')), '__NULL__'].includes(await setting('design.checkout_header.global.draft')) && (await stateText()).includes('Публикувано'), await stateText());
    await field('logo_height_d').fill('40');
    await apply();
    await button('Върни публикуваното').click();
    await confirmModal();
    check('Количка и поръчка: «Върни публикуваното» drops the new draft', (await setting('design.checkout_header.global.draft')) === '__NULL__' && (await field('logo_height_d').inputValue()) === '44', await field('logo_height_d').inputValue());

    // ============================================================ Футър: «Начални настройки» fills the working copy only
    await open('footer');
    const footerPublished = await setting('footer.layout.published');
    await button('Начални настройки').click();
    await confirmModal();
    check('Футър: «Начални настройки» stores the working copy as the draft; the published footer is untouched', (await setting('footer.layout.draft')) !== '__NULL__' && (await setting('footer.layout.published')) === footerPublished);
    await button('Върни публикуваното').click();
    await confirmModal();
    check('Футър: «Върни публикуваното» drops it', (await setting('footer.layout.draft')) === '__NULL__');
    await page.locator('.ds-cell').first().locator('.ds-cell-title').click();
    await sleep(500);
    await page.locator('.ds-chip-add').first().click();
    await settle();
    await publish();
    await settle();
    check('Футър: «Публикувай» makes a structural change the published footer and forgets the draft', (await setting('footer.layout.published')) !== footerPublished && (await setting('footer.layout.draft')) === '__NULL__' && (await stateText()).includes('Публикувано'), await stateText());

    await shot('footer-final', page.locator('.fi-header').first());
    console.error('known /api/cart 500s from the preview frames: ' + cartApi500.length);
    await admin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        try {
            const report = JSON.parse(php(RESTORE));
            console.error('restored: ' + JSON.stringify(report));
            check('the settings table is byte-for-byte what it was (a checksum of every row)', report.sum === report.was, JSON.stringify(report));
            check('the throw-away admin is removed and the appearance/ files are the ones that were there', report.staff === 0 && report.appearanceFiles === report.appearanceWas, JSON.stringify(report));
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
