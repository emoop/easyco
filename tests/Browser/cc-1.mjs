// A REAL-BROWSER check of CC-1 (the cookie / ads consent banner), at 1280px and 360px. Run by hand against a dev site that has the CC-1 code and a built `public/build` (npm run build).
//   WHAT IT WRITES to the DEV database: the `site_settings` rows (the `cookies.*` keys, set through CookieSettings::save) and ONE throw-away Administrator for the admin page screenshots.
//   BEFORE the first write the whole `site_settings` table is ARCHIVED (every row, id + checksum) to a file in OUT; at the end it is RESTORED EXACTLY from the archive and the checksum is verified
//   (the NF-1.1 rule). The numbers before and after are printed. The throw-away admin is deleted. No page is purged, no stylesheet is recompiled (the colour stays the default #111111).
//   It ALSO inserts two `menu_items` rows of the kind `consent` («Настройки за бисквитки») into the dev footer menus and DELETES them at the end (counts printed); the compiled menus live in `site_settings`, so the archive restores them.
//   Screenshots go to OUT (outside the project). NOT part of `php artisan test`.
//
//   CHROMIUM=<chrome.exe> AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/cc-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node cc-1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 120000 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const TAG = `cc1-${Date.now()}`;
const STAFF = `${TAG}-admin@example.test`;
const PASSWORD = 'Cc1-' + Math.random().toString(36).slice(2) + 'Aa1!';
const SNAP_FILE = join(OUT, 'site_settings-archive.json').replace(/\\/g, '/');

const SUMMARY = String.raw`use Illuminate\Support\Facades\DB; $rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(); echo json_encode(['count' => count($rows), 'sum' => md5(json_encode($rows))]);`;
const ARCHIVE = String.raw`use Illuminate\Support\Facades\DB; $rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(); file_put_contents('${SNAP_FILE}', json_encode($rows)); echo json_encode(['count' => count($rows), 'sum' => md5(json_encode($rows))]);`;
const RESTORE = String.raw`use Illuminate\Support\Facades\DB; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo 'restored';`;
const MENU_COUNT = String.raw`echo Illuminate\Support\Facades\DB::table('menu_items')->count();`;
const ADD_ITEMS = String.raw`use Illuminate\Support\Facades\DB; $ids = []; foreach (['footer', 'footer-menu'] as $key) { $menu = DB::table('menus')->where('key', $key)->value('id'); if ($menu !== null) { $ids[] = DB::table('menu_items')->insertGetId(['menu_id' => $menu, 'sort' => 900, 'type' => 'consent', 'submenu' => 'none', 'created_at' => now(), 'updated_at' => now()]); } } app(App\Storefront\Menu\MenuRecompiler::class)->now(); Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo json_encode($ids);`;
const DEL_ITEMS = String.raw`Illuminate\Support\Facades\DB::table('menu_items')->where('type', 'consent')->where('sort', 900)->delete(); echo 'deleted';`;
const STAFF_COUNT = String.raw`echo Illuminate\Support\Facades\DB::table('staff')->count();`;
const SEED_STAFF = String.raw`use EasyCo\Staff\Staff; use EasyCo\Staff\Contracts\{RoleRepository, StaffRepository, PasswordHasher}; $role = app(RoleRepository::class)->findSystemRoleByName('Administrator'); $staff = Staff::create('${STAFF}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'Audit Admin', $role); app(StaffRepository::class)->save($staff); echo 'seeded';`;
const CLEAN_STAFF = String.raw`Illuminate\Support\Facades\DB::table('staff')->where('email', '${STAFF}')->delete(); echo 'cleaned';`;

/** CookieSettings::save with the shipped texts and these overrides; the appearance payload is forgotten (no recompile, no purge, no stylesheet write). */
const configure = (o) => php(String.raw`use App\Settings\CookieSettings; $d = array_replace(['enabled' => true, 'position' => 'center', 'view' => 'summary', 'months' => 12, 'page' => null, 'color' => '#111111', 'texts' => ['bg' => CookieSettings::defaults('bg'), 'en' => CookieSettings::defaults('en')]], json_decode('${JSON.stringify(o)}', true)); app(CookieSettings::class)->save($d); Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo 'configured';`);
const bumpText = () => php(String.raw`use App\Settings\CookieSettings; $d = app(CookieSettings::class)->current(); $d['page'] = $d['page']; $d['texts']['bg']['title'] .= ' '; $d['texts']['bg']['title'] = trim($d['texts']['bg']['title']) === CookieSettings::defaults('bg')['title'] ? 'Уважаваме вашата поверителност!' : CookieSettings::defaults('bg')['title']; app(CookieSettings::class)->save($d); Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo 'bumped';`);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const report = { before: JSON.parse(php(SUMMARY)), staffBefore: php(STAFF_COUNT), menuItemsBefore: php(MENU_COUNT), checks: [], failed: 0 };
const check = (name, ok, detail = '') => { report.checks.push({ name, ok, detail }); ok || report.failed++; };

const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const host = new URL(BASE).host;

/** the computed look of a button: what must be IDENTICAL for the two equal buttons */
const look = (locator) => locator.evaluate((el) => {
    const s = getComputedStyle(el);
    const r = el.getBoundingClientRect();

    return { w: Math.round(r.width * 100) / 100, h: Math.round(r.height * 100) / 100, x: Math.round(r.x), y: Math.round(r.y), color: s.color, bg: s.backgroundColor, border: s.borderTopColor + s.borderTopWidth, fontSize: s.fontSize, fontWeight: s.fontWeight, fontFamily: s.fontFamily, radius: s.borderTopLeftRadius, text: el.textContent.trim() };
});
const same = (a, b, keys) => keys.every((k) => a[k] === b[k]);
const EQUAL_KEYS = ['w', 'h', 'color', 'bg', 'border', 'fontSize', 'fontWeight', 'fontFamily', 'radius'];

const newVisitor = async (browser, width = 1280, height = 900) => {
    const context = await browser.newContext({ viewport: { width, height }, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const requests = [];
    page.on('request', (request) => requests.push(request.url()));

    return { context, page, requests };
};
const banner = (page) => page.locator('[data-sf-cc]');
const cookieOf = async (context) => (await context.cookies()).find((c) => c.name === 'easyco_consent') ?? null;

let browser = null;
let archived = false;

try {
    report.archive = JSON.parse(php(ARCHIVE));
    archived = true;
    php(SEED_STAFF);
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: true });

    configure({ position: 'center', view: 'summary' });

    // ---- 1. first visit: shown, the two first-layer buttons are IDENTICAL; nothing optional before a choice --------------------------------
    {
        const { context, page, requests } = await newVisitor(browser);
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        check('1 first visit shows the banner', await banner(page).isVisible());
        const accept = await look(page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="accept"]'));
        const reject = await look(page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]'));
        check('1 the two first-layer buttons are identical (width, height, colour, background, border, font size, weight, family, radius)', same(accept, reject, EQUAL_KEYS), JSON.stringify({ accept, reject }));
        check('1 the settings link is below them', (await look(page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="details"]'))).y > accept.y);
        check('1 the dialog is 520px wide at most and centred', await page.locator('[data-sf-cc-dialog]').evaluate((el) => { const r = el.getBoundingClientRect(); return r.width <= 520.5 && Math.abs((r.x + r.width / 2) - window.innerWidth / 2) < 2; }));
        check('1 the page is dimmed (modal)', (await page.locator('[data-sf-cc]').evaluate((el) => getComputedStyle(el).backgroundColor)).includes('0.55'));
        check('1 no cookie before a choice', (await context.cookies()).length === 0, JSON.stringify((await context.cookies()).map((c) => c.name)));
        check('1 no third-party request before a choice', requests.every((u) => new URL(u).host === host || u.startsWith('data:')), JSON.stringify(requests.filter((u) => ! u.startsWith('data:') && new URL(u).host !== host)));
        check('1 no Close button and no inline handler', await page.locator('[data-sf-cc] [aria-label*="lose"], [data-sf-cc] [aria-label*="атвор"]').count() === 0 && await page.locator('[data-sf-cc] [onclick], [data-sf-cc] [style]').count() === 0);
        await page.screenshot({ path: join(OUT, 'banner-center-layer1-1280.png') });
        await context.close();
    }

    // ---- 2. accept all / only necessary: the cookie, its flags and lifetime, and the reload ----------------------------------------------
    for (const [action, ads] of [['accept', '1'], ['reject', '0']]) {
        const { context, page } = await newVisitor(browser);
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await page.locator(`[data-sf-cc-layer="summary"] [data-sf-cc-action="${action}"]`).click();
        const cookie = await cookieOf(context);
        const value = decodeURIComponent(cookie?.value ?? '');
        check(`2 «${action}» writes ads=${ads} in the documented format`, new RegExp(`^v=1&ads=${ads}&t=\\d{10}$`).test(value), value);
        const days = cookie === null ? 0 : (cookie.expires - Date.now() / 1000) / 86400;
        check(`2 «${action}» cookie flags: Path=/, SameSite=Lax, Secure, not HttpOnly, 360 days`, cookie !== null && cookie.path === '/' && cookie.sameSite === 'Lax' && cookie.secure === true && cookie.httpOnly === false && Math.abs(days - 360) < 1, JSON.stringify(cookie));
        check(`2 «${action}» hides the banner`, ! (await banner(page).isVisible()));
        await page.reload({ waitUntil: 'networkidle' });
        check(`2 «${action}»: after a reload the banner stays hidden`, ! (await banner(page).isVisible()));
        check(`2 «${action}»: the API reports the choice`, await page.evaluate((expected) => window.EasyCoConsent.has('ads') === expected && window.EasyCoConsent.has('necessary') === true, ads === '1'));
        await context.close();
    }

    // ---- 3. «Настройки» → layer 2: the switch is off, both equal buttons and «Запази избора» ------------------------------------------
    {
        const { context, page } = await newVisitor(browser);
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="details"]').click();
        const toggle = page.locator('[data-sf-cc-switch="ads"]');
        check('3 layer 2: the ads switch is OFF', (await toggle.getAttribute('aria-checked')) === 'false');
        check('3 layer 2: «← Назад» is shown when reached from layer 1', await page.locator('[data-sf-cc-action="back"]').isVisible());
        const accept = await look(page.locator('[data-sf-cc-layer="details"] [data-sf-cc-action="accept"]'));
        const reject = await look(page.locator('[data-sf-cc-layer="details"] [data-sf-cc-action="reject"]'));
        const save = await look(page.locator('[data-sf-cc-layer="details"] [data-sf-cc-action="save"]'));
        check('3 layer 2: both equal buttons present and identical', same(accept, reject, EQUAL_KEYS) && accept.y === reject.y, JSON.stringify({ accept, reject }));
        check('3 layer 2: «Запази избора» has the same colour and weight, full width', same(accept, save, ['color', 'bg', 'fontWeight', 'fontFamily', 'radius', 'h']) && save.w > accept.w * 1.8, JSON.stringify(save));
        await page.screenshot({ path: join(OUT, 'banner-center-layer2-1280.png') });
        await toggle.click();
        check('3 the switch toggles', (await toggle.getAttribute('aria-checked')) === 'true');
        await page.locator('[data-sf-cc-layer="details"] [data-sf-cc-action="save"]').click();
        check('3 «Запази избора» saves the switch state (ads=1)', /ads=1/.test(decodeURIComponent((await cookieOf(context))?.value ?? '')));

        // 5. the footer link reopens layer 2 with the CURRENT choice and no «← Назад»
        check('5 the footer link is visible once a choice exists', await page.locator('.sf-cc-reopen').isVisible());
        await page.screenshot({ path: join(OUT, 'footer-bar-after-choice-1280.png'), fullPage: false });
        await page.locator('.sf-cc-reopen').scrollIntoViewIfNeeded();
        await page.locator('.sf-cc-reopen').click();
        check('5 reopened at layer 2', await page.locator('[data-sf-cc-layer="details"]').isVisible() && ! (await page.locator('[data-sf-cc-layer="summary"]').isVisible()));
        check('5 reopened: no «← Назад»', ! (await page.locator('[data-sf-cc-action="back"]').isVisible()));
        check('5 reopened: the switch shows the current choice (on)', (await page.locator('[data-sf-cc-switch="ads"]').getAttribute('aria-checked')) === 'true');
        await page.locator('[data-sf-cc-layer="details"] [data-sf-cc-action="reject"]').click();
        check('5 withdrawing is as easy as giving: one click on «Само необходими» sets ads=0', /ads=0/.test(decodeURIComponent((await cookieOf(context))?.value ?? '')));
        await context.close();
    }

    // ---- 4. a changed text_version shows the banner again ---------------------------------------------------------------------------------
    {
        const { context, page } = await newVisitor(browser);
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="accept"]').click();
        await page.reload({ waitUntil: 'networkidle' });
        check('4 with a valid choice the banner is hidden', ! (await banner(page).isVisible()));
        bumpText();
        await page.reload({ waitUntil: 'networkidle' });
        check('4 a changed text_version shows the banner again', await banner(page).isVisible());
        check('4 …and ads is NOT granted until the new choice', await page.evaluate(() => window.EasyCoConsent.has('ads') === false && window.EasyCoConsent.get() === null));
        await context.close();
    }

    // ---- 6. keyboard (centre): focus moves in, is trapped, no Escape close, a visible focus ring -------------------------------------------
    {
        const { context, page } = await newVisitor(browser);
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        check('6 focus moves into the dialog on open', await page.evaluate(() => document.querySelector('[data-sf-cc-dialog]').contains(document.activeElement)));
        let inside = true;

        for (let i = 0; i < 14; i++) {
            await page.keyboard.press('Tab');
            inside = inside && await page.evaluate(() => document.querySelector('[data-sf-cc-dialog]').contains(document.activeElement));
        }

        check('6 Tab is trapped inside the dialog (14 presses)', inside);
        let insideBack = true;

        for (let i = 0; i < 6; i++) {
            await page.keyboard.press('Shift+Tab');
            insideBack = insideBack && await page.evaluate(() => document.querySelector('[data-sf-cc-dialog]').contains(document.activeElement));
        }

        check('6 Shift+Tab is trapped too', insideBack);
        await page.keyboard.press('Escape');
        check('6 Escape does not close it', await banner(page).isVisible());
        const ring = await page.evaluate(() => { const s = getComputedStyle(document.activeElement); return { style: s.outlineStyle, width: parseFloat(s.outlineWidth) }; });
        check('6 the focus ring is clearly visible (3px outline)', ring.style !== 'none' && ring.width >= 2, JSON.stringify(ring));
        check('6 the page behind is locked and inert', await page.evaluate(() => document.documentElement.classList.contains('sf-cc-lock') && [...document.body.children].filter((c) => ! c.matches('[data-sf-cc]') && c.tagName !== 'SCRIPT').every((c) => c.hasAttribute('inert'))));
        // keyboard only: the first focus is «Приемам всички»; Enter decides (no pointer used)
        await page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="accept"]').focus();
        await page.keyboard.press('Enter');
        check('6 a keyboard-only choice works (Enter on the focused first button)', /ads=1/.test(decodeURIComponent((await cookieOf(context))?.value ?? '')) && ! (await banner(page).isVisible()));
        await context.close();
    }

    // ---- 7. phone, 360px: no horizontal scroll, buttons stacked and equal; the bottom card does not block the page ------------------------
    {
        const { context, page } = await newVisitor(browser, 360, 800);
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        const accept = await look(page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="accept"]'));
        const reject = await look(page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]'));
        check('7 phone (centre): no horizontal scroll', ! overflow);
        check('7 phone (centre): the buttons are stacked, full width and equal', accept.x === reject.x && reject.y > accept.y && same(accept, reject, EQUAL_KEYS) && accept.w > 360 - 2 * 16 - 60, JSON.stringify({ accept, reject }));
        check('7 phone (centre): the dialog keeps a 16px side margin', await page.locator('[data-sf-cc-dialog]').evaluate((el) => { const r = el.getBoundingClientRect(); return r.x >= 15 && r.right <= window.innerWidth - 15; }));
        await page.screenshot({ path: join(OUT, 'banner-center-layer1-360.png') });
        await page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="details"]').click();
        check('7 phone layer 2: no horizontal scroll, the dialog scrolls inside itself when tall', ! (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) && await page.locator('[data-sf-cc-dialog]').evaluate((el) => el.getBoundingClientRect().height <= window.innerHeight - 31));
        await page.screenshot({ path: join(OUT, 'banner-center-layer2-360.png') });
        await context.close();

        configure({ position: 'bottom', view: 'summary' });

        const wide = await newVisitor(browser, 1280, 900);
        await wide.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const card = await wide.page.locator('[data-sf-cc-dialog]').evaluate((el) => { const r = el.getBoundingClientRect(); return { w: r.width, bottom: window.innerHeight - r.bottom }; });
        check('7 bottom card (1280): up to 920px wide, 24px from the bottom', card.w <= 920.5 && Math.abs(card.bottom - 24) < 2, JSON.stringify(card));
        const sideBySide = await look(wide.page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="accept"]'));
        const sideReject = await look(wide.page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]'));
        check('7 bottom card (1280): the two buttons are stacked on the right, 210px, equal', sideBySide.x === sideReject.x && sideReject.y > sideBySide.y && Math.abs(sideBySide.w - 210) < 2 && same(sideBySide, sideReject, EQUAL_KEYS), JSON.stringify({ sideBySide, sideReject }));
        await wide.page.screenshot({ path: join(OUT, 'banner-bottom-1280.png') });
        await wide.context.close();

        const phone = await newVisitor(browser, 360, 800);
        await phone.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        check('7 bottom card is non-modal: the page is not locked or inert, and it scrolls', await phone.page.evaluate(async () => { const locked = document.documentElement.classList.contains('sf-cc-lock'); const inert = [...document.body.children].some((c) => c.hasAttribute('inert')); window.scrollTo(0, 120); await new Promise((r) => setTimeout(r, 100)); return ! locked && ! inert && (window.scrollY > 0 || document.documentElement.scrollHeight <= window.innerHeight); }));
        check('7 bottom card (360): no horizontal scroll', ! (await phone.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
        check('7 bottom card does not cover the top of the page (a click reaches the header)', await phone.page.evaluate(() => { const el = document.elementFromPoint(window.innerWidth / 2, 20); return el !== null && ! el.closest('[data-sf-cc]'); }));
        await phone.page.screenshot({ path: join(OUT, 'banner-bottom-360.png') });
        await phone.context.close();

        // the footer's bottom bar, as the footer's own lower line
        configure({ position: 'center', view: 'summary' });
        const bar = await newVisitor(browser, 1280, 900);
        await bar.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await bar.page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]').click();
        await bar.page.locator('.sf-cc-bar').scrollIntoViewIfNeeded();
        await bar.page.screenshot({ path: join(OUT, 'footer-bar-1280.png') });
        await bar.context.close();
        const barPhone = await newVisitor(browser, 360, 800);
        await barPhone.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await barPhone.page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]').click();
        await barPhone.page.locator('.sf-cc-bar').scrollIntoViewIfNeeded();
        await barPhone.page.screenshot({ path: join(OUT, 'footer-bar-360.png') });
        check('7 the footer bar link at 360: no horizontal scroll', ! (await barPhone.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
        await barPhone.context.close();
    }

    // ---- the menu item «Настройки за бисквитки» and the fallback strip ------------------------------------------------------------------------
    {
        configure({ position: 'center', view: 'summary' });
        const before = await newVisitor(browser);
        await before.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        check('M the fallback strip is printed while no menu holds the item', await before.page.locator('[data-sf-cc-bar]').count() === 1);
        await before.context.close();

        report.itemsAdded = php(ADD_ITEMS);
        const visitor = await newVisitor(browser);
        await visitor.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const links = visitor.page.locator('a[data-consent-open]');
        check('M the menu item is printed as a normal link with data-consent-open', await links.count() >= 1, String(await links.count()));
        check('M once such an item exists the fixed strip is NOT printed', await visitor.page.locator('[data-sf-cc-bar]').count() === 0);
        const href = await links.first().getAttribute('href');
        check('M its href is the policy page or # (nothing is picked on dev)', href === '#' || (href ?? '').startsWith('/info/'), String(href));
        check('M it carries no inline handler', await links.first().evaluate((el) => ! el.getAttributeNames().some((n) => n.startsWith('on'))));
        await visitor.page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]').click();
        await links.first().scrollIntoViewIfNeeded();
        await visitor.page.screenshot({ path: join(OUT, 'menu-item-footer-1280.png') });
        await links.first().click();
        check('M a click opens layer 2 (event delegation)', await visitor.page.locator('[data-sf-cc-layer="details"]').isVisible());
        check('M …with no «← Назад» and the current choice (off)', ! (await visitor.page.locator('[data-sf-cc-action="back"]').isVisible()) && (await visitor.page.locator('[data-sf-cc-switch="ads"]').getAttribute('aria-checked')) === 'false');
        check('M the page did not navigate (preventDefault)', new URL(visitor.page.url()).pathname === '/');
        await visitor.context.close();

        const phone = await newVisitor(browser, 360, 800);
        await phone.page.goto(BASE + '/', { waitUntil: 'networkidle' });
        await phone.page.locator('[data-sf-cc-layer="summary"] [data-sf-cc-action="reject"]').click();
        await phone.page.locator('a[data-consent-open]').first().scrollIntoViewIfNeeded();
        check('M phone: no horizontal scroll with the menu item', ! (await phone.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
        await phone.page.screenshot({ path: join(OUT, 'menu-item-footer-360.png') });
        await phone.context.close();
    }

    // ---- the admin page and its preview at 1280 and 360 -----------------------------------------------------------------------------------
    for (const [label, width, height] of [['wide', 1280, 1000], ['phone', 360, 800]]) {
        const context = await browser.newContext({ viewport: { width, height }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', STAFF);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(BASE + '/admin/privacy-cookies', { waitUntil: 'networkidle' });
        await wait(800);
        const info = await page.evaluate(() => ({ overflowX: document.documentElement.scrollWidth > window.innerWidth + 1, helperParagraphs: document.querySelectorAll('.fi-fo-field-wrp-hint, .fi-fo-field-wrp-helper-text').length, frame: document.querySelector('.cc-preview-frame')?.getBoundingClientRect().toJSON() ?? null, colWidths: [...document.querySelectorAll('.fi-fo-field')].slice(0, 6).map((el) => Math.round(el.getBoundingClientRect().width)) }));
        check(`admin ${label}: no horizontal scroll`, ! info.overflowX, JSON.stringify(info));
        check(`admin ${label}: the preview frame is there`, info.frame !== null && info.frame.width > 200);
        const frame = page.frameLocator('.cc-preview-frame');
        check(`admin ${label}: the preview shows the real banner, static`, await frame.locator('.sf-cc-dialog').isVisible() && await page.locator('.cc-preview-frame').evaluate((el) => el.contentDocument?.querySelectorAll('script').length === 0));
        check(`admin ${label}: the preview wrote no cookie`, ! (await context.cookies()).some((c) => c.name === 'easyco_consent'));
        check(`admin ${label}: the visible warning shows while no policy page is picked`, await page.locator('.cc-warning').isVisible());
        label === 'wide' && check('admin wide: NO fallback-strip warning while a guest-visible menu holds the item', await page.locator('.cc-warning-strip').count() === 0);
        await page.screenshot({ path: join(OUT, `admin-cookies-${label}.png`), fullPage: true });

        // the warning comes back when the items are gone (deleted here, the compiled menus rebuilt)
        if (label === 'wide') {
            php(DEL_ITEMS);
            php(String.raw`app(App\Storefront\Menu\MenuRecompiler::class)->now(); Illuminate\Support\Facades\Cache::forget(App\Storefront\Appearance\StorefrontAppearance::CACHE_KEY); echo 'rebuilt';`);
            await page.goto(BASE + '/admin/privacy-cookies', { waitUntil: 'networkidle' });
            await wait(600);
            check('admin wide: the visible fallback-strip warning shows once no menu holds the item', await page.locator('.cc-warning-strip').isVisible());
            await page.screenshot({ path: join(OUT, 'admin-cookies-fallback-warning-wide.png'), fullPage: false });

            // the menu editor: the panel adds the kind, nothing to type (read-only: nothing is saved)
            await page.goto(BASE + '/admin/menus', { waitUntil: 'networkidle' });
            await wait(800);
            await page.locator('.me-panel-head', { hasText: 'Настройки за бисквитки' }).click();
            await page.locator('.me-body', { hasText: 'Връзка, която отваря банера' }).getByRole('button', { name: 'Добави в менюто' }).click();
            await wait(400);
            const card = page.locator('.me-card', { hasText: 'Настройки за бисквитки' }).last();
            check('admin menus: the editor adds a «Настройки за бисквитки» card', await card.count() === 1);
            await card.locator('button.me-title').click();
            await card.scrollIntoViewIfNeeded();
            const fields = { text: await card.locator('input[type=text]:visible').count(), checkbox: await card.locator('input[type=checkbox]:visible').count(), select: await card.locator('select:visible').count() };
            check('admin menus: the card has no text, address, badge or submenu field and no new-tab box (only «Показвай»)', fields.text === 0 && fields.checkbox === 1 && fields.select === 0, JSON.stringify(fields));
            check('admin menus: the panel hint wraps (no horizontal scroll)', ! (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
            await page.screenshot({ path: join(OUT, 'admin-menus-consent-card-1280.png'), fullPage: false });
        }

        await context.close();
    }
} finally {
    if (browser) { await browser.close(); }

    try { php(CLEAN_STAFF); php(DEL_ITEMS); } catch (e) { report.cleanError = String(e); }

    if (archived) {
        try {
            php(RESTORE);
            report.after = JSON.parse(php(SUMMARY));
            report.restoredExactly = report.after.count === report.archive.count && report.after.sum === report.archive.sum;
        } catch (e) { report.restoreError = String(e); }
    }

    report.staffAfter = php(STAFF_COUNT);
    report.menuItemsAfter = php(MENU_COUNT);
}

console.log(JSON.stringify({ before: report.before, archive: report.archive, after: report.after, restoredExactly: report.restoredExactly, staffBefore: report.staffBefore, staffAfter: report.staffAfter, menuItemsBefore: report.menuItemsBefore, menuItemsAfter: report.menuItemsAfter, failed: report.failed, checks: report.checks.map((c) => `${c.ok ? 'PASS' : 'FAIL'}  ${c.name}${c.ok ? '' : '  ' + c.detail}`), cleanError: report.cleanError, restoreError: report.restoreError }, null, 1));
process.exit(report.failed === 0 && report.restoredExactly === true ? 0 : 1);
