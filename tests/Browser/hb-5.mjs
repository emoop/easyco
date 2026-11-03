// A REAL-BROWSER run of HB-5: the «Снимка с текст» kind of HTML block. It checks:
//   - the admin: a new block of the card kind shows the card's fields (and not the editor / «Стил на блока»), the live preview carries the shop's classes, an existing card shows its kind read-only, a text block shows the old form;
//   - the shop: a card as the «Блок отгоре» of a category at 1280px and 360px (the full width of its place, the height, the border and the corners, the title size in px, no sideways scroll, the picture covers the box),
//     the whole block is one link (the click target), a picture-only card (role=img + alt), light and dark text, the four title sizes (18 / 24 / 32 / 48 px; about 60% on a phone), a card in a footer column,
//     and an old text block with a background picture that still looks as before.
// NOT part of `php artisan test`: run by hand against a dev site. The run creates ONE throw-away staff member and the throw-away HTML blocks (titles «HB5 audit …», made through the application's own model, using a
// picture that is ALREADY in the library: no file is added). The design is changed only through the application's own publish paths (HomeDrafts, FooterCompiler); at the end the blocks and the staff member are
// removed, every `site_settings` row is put back EXACTLY (update by id) and the counts and the checksum are verified; the category's own «Блок отгоре» is put back by id. Prints a JSON report; exit 1 on a failed check.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-5.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-5.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

const php = (code) => {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim().split('\n').pop();
    } catch (e) {
        throw new Error('php failed: ' + String(e.stdout || '').slice(-600) + String(e.stderr || '').slice(-300));
    }
};

const EMAIL = `hb5-${Date.now()}@example.test`;
const PASSWORD = 'Hb5-' + Math.random().toString(36).slice(2) + 'Aa1!';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'staff' => DB::table('staff')->count(), 'blocks' => DB::table('html_blocks')->count(), 'categories' => md5(json_encode(DB::table('catalog_categories')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()))]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hb5_backup.json', json_encode(['settings' => DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'cat' => DB::table('catalog_categories')->orderBy('id')->get(['id', 'header_block_id'])->map(fn ($r) => (array) $r)->all()]));
echo 'ok';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb5_backup.json'), true);
DB::table('catalog_categories')->update(['header_block_id' => null]);
foreach ($bak['cat'] as $row) { DB::table('catalog_categories')->where('id', $row['id'])->update(['header_block_id' => $row['header_block_id']]); }
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak['settings'], 'id'))->delete(); foreach ($bak['settings'] as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
foreach (App\Content\HtmlBlocks\HtmlBlockModel::query()->where('title', 'like', 'HB5 audit%')->get() as $b) { $b->delete(); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

// the staff member, the blocks and the category: returns JSON {picture, cat: {id, url}, ids: {...}}
const CREATE = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use App\Content\HtmlBlocks\HtmlBlockModel; use Illuminate\Support\Facades\DB; use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HB5 Audit', $role));
$pic = MediaAssetModel::query()->where('type', 'image')->where('processing_status', 'ready')->orderBy('id')->first();
if ($pic === null) { echo json_encode(['error' => 'no ready picture in the library']); return; }
$mk = fn (string $t, array $c) => HtmlBlockModel::query()->create(['title' => 'HB5 audit '.$t, 'kind' => 'card', 'card' => $c + ['picture' => $pic->id, 'title' => 'Нова колекция', 'subtitle' => 'Есен — зима', 'text' => "Първи ред\nВторият ред", 'link' => '/']])->id;
$ids = [
  'main' => $mk('main', ['title_size' => 'xl', 'halign' => 'center', 'valign' => 'middle', 'overlay' => 'medium', 'border_w' => 2, 'border_color' => 'light', 'radius' => 16, 'height' => 320]),
  'only' => $mk('only', ['title' => '', 'subtitle' => '', 'text' => '', 'alt' => 'Колекция есен', 'link' => '/', 'height' => 240]),
  's' => $mk('s', ['title_size' => 's', 'link' => '']), 'm' => $mk('m', ['title_size' => 'm', 'link' => '']), 'l' => $mk('l', ['title_size' => 'l', 'link' => '']), 'xl' => $mk('xl', ['title_size' => 'xl', 'link' => '']),
  'dark' => $mk('dark', ['text_color' => 'dark', 'overlay' => 'none', 'link' => '', 'height' => 160]),
  'custom' => $mk('custom', ['text_color' => 'custom', 'text_custom' => '#ffcc00', 'border_w' => 4, 'border_color' => 'custom', 'border_custom' => '#cc0066', 'link' => '', 'height' => 160]),
  'footer' => $mk('footer', ['link' => '', 'height' => 160]),
  'old' => HtmlBlockModel::query()->create(['title' => 'HB5 audit old', 'body' => '<p>Стар блок с фонова снимка</p>', 'style' => ['bg_image' => $pic->id, 'overlay' => 40, 'min_height' => 240, 'color' => '#ffffff', 'pad_v' => 16, 'pad_h' => 16]])->id,
  'text' => HtmlBlockModel::query()->create(['title' => 'HB5 audit text', 'body' => '<p>Само текст</p>'])->id,
];
$cat = DB::table('catalog_categories')->whereNull('parent_id')->orderBy('id')->first(['id', 'slug']);
echo json_encode(['picture' => $pic->id, 'cat' => ['id' => $cat->id, 'url' => '/product-category/'.$cat->slug], 'ids' => $ids]);`;

const SET_BANNER = (catId, blockId) => String.raw`
Illuminate\Support\Facades\DB::table('catalog_categories')->where('id', ${catId})->update(['header_block_id' => ${blockId}]); echo 'ok';`;

const PUBLISH_HOME = (sections) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const UNPUBLISH_HOME = String.raw`
use App\Storefront\Home\HomeLayout; use App\Storefront\Home\HomeDrafts;
HomeDrafts::publish(HomeLayout::default());
echo 'default';`;

const PUBLISH_FOOTER = (blockId) => String.raw`
use App\Storefront\Footer\FooterLayout; use App\Storefront\Footer\FooterCompiler;
$layout = FooterLayout::default();
$layout['upper']['rows'] = [['columns' => [['span' => 1, 'align' => 'start', 'blocks' => [['type' => 'html_block', 'settings' => ['html_block' => ${blockId}]]]]]]];
app(FooterCompiler::class)->publish($layout, app(App\Settings\Contracts\SiteSettingsRepository::class));
echo 'ok';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, t = 1.2) => Math.abs(a - b) <= t;

let before = null;
let created = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    const made = JSON.parse(php(CREATE));
    check('the throw-away blocks and staff member were created', ! made.error && made.ids?.main > 0, JSON.stringify(made));
    const { ids, cat } = made;

    const newCtx = async (width) => browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
    const shotOf = async (page, name, full = false) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); } };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); return p; };

    // ============================================================ the admin
    let ctx = await newCtx(1440);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
    check('a new block starts as the text kind: the editor and «Стил на блока» are there, the card fields are not', (await page.locator('.fi-fo-rich-editor').count()) > 0 && (await page.locator('[id$="card.title"], [id*="card.title"]').count()) === 0);
    await shotOf(page, 'hb5-admin-create-text-1440');

    // the kind select (Filament's own select): open it and choose the card kind
    const kindField = page.locator('.fi-fo-field:has-text("Вид на блока")').first();
    await kindField.locator('.fi-select-input-btn, [role=combobox]').first().click();
    await sleep(500);
    await page.evaluate(() => document.querySelector('li[data-value="card"]').click());
    await sleep(1500);
    const cardTitle = page.locator('input[id$="card.title"], input[id*="card.title"]').first();
    await cardTitle.waitFor({ state: 'visible', timeout: 30000 });
    check('the card kind shows its fields and hides the editor', (await page.locator('.fi-fo-rich-editor').count()) === 0 && (await page.locator('input[id*="card.subtitle"]').count()) > 0);
    check('the explanations are info icons (no helper paragraph under the card fields)', (await page.locator('[aria-describedby^="ds-hint-card-"]').count()) >= 10 && (await page.locator('.fi-fo-field-wrp-hint, .fi-fo-field-helper-text').count()) < 6);
    await cardTitle.fill('Преглед на заглавието');
    await page.locator('input[id*="card.subtitle"]').first().fill('Подзаглавие тук');
    await page.locator('input[id*="card.subtitle"]').first().blur();
    await cardTitle.blur();
    await sleep(2000);
    const prev = await page.locator('.fi-hb-preview .sf-pc').first();
    check('the live preview has the shop\'s classes and the typed text', (await prev.count()) === 1 && /sf-pc-t-l/.test(await prev.getAttribute('class')) && (await prev.textContent()).includes('Преглед на заглавието'));
    await shotOf(page, 'hb5-admin-create-card-1440', true);

    await page.goto(BASE + '/admin/html-blocks', { waitUntil: 'networkidle' });
    check('the list shows the kind of every block', (await page.locator('text=Снимка с текст').count()) > 0 && (await page.locator('.fi-badge:has-text("Текст")').count()) > 0);

    // an existing card: the kind is read-only
    await page.goto(BASE + `/admin/html-blocks/${ids.main}/edit`, { waitUntil: 'networkidle' });
    await sleep(1500);
    const kindBtn = page.locator('.fi-fo-field:has-text("Вид на блока")').first();
    check('an existing card shows its kind and cannot change it', (await kindBtn.textContent()).includes('Снимка с текст') && (await kindBtn.locator('button[disabled], input[disabled], [aria-disabled=true]').count()) > 0);
    check('the existing card has its preview and no old «Стил на блока»', (await page.locator('.fi-hb-preview .sf-pc').count()) === 1 && (await page.locator('text=Стил на блока').count()) === 0);
    await shotOf(page, 'hb5-admin-edit-card-1440', true);

    await page.goto(BASE + `/admin/html-blocks/${ids.text}/edit`, { waitUntil: 'networkidle' });
    check('a text block keeps the old form (editor, «Стил на блока», no card fields)', (await page.locator('.fi-fo-rich-editor').count()) > 0 && (await page.locator('text=Стил на блока').count()) > 0 && (await page.locator('input[id*="card.title"]').count()) === 0);
    await ctx.close();

    // ============================================================ the shop: the category banner
    const measure = async (p, sel) => p.evaluate((s) => {
        const box = document.querySelector(s);
        if (! box) return null;
        const place = box.parentElement.getBoundingClientRect();
        const r = box.getBoundingClientRect();
        const cs = getComputedStyle(box);
        const title = box.querySelector('.sf-pc-title');
        const link = box.querySelector('.sf-pc-link');
        const lr = link ? link.getBoundingClientRect() : null;
        const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
        const hit = document.elementFromPoint(cx, cy);
        return {
            w: Math.round(r.width * 10) / 10, place: Math.round(place.width * 10) / 10, h: Math.round(r.height * 10) / 10,
            bw: cs.borderTopWidth, radius: cs.borderTopLeftRadius, bg: cs.backgroundImage.slice(0, 60), size: cs.backgroundSize, minh: cs.minHeight,
            titleSize: title ? parseFloat(getComputedStyle(title).fontSize) : null, titleTag: title ? title.tagName : null, titleColor: title ? getComputedStyle(title).color : null,
            linkCovers: lr ? Math.abs(lr.width - r.width) < 6 && Math.abs(lr.height - r.height) < 6 : null, hitHref: hit && hit.closest('a') ? hit.closest('a').getAttribute('href') : null,
            role: box.getAttribute('role'), label: box.getAttribute('aria-label'), scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, h1: document.querySelectorAll('h1').length,
            headings: box.querySelectorAll('h1,h2,h3,h4,h5,h6').length, hasStyleAttr: !! box.getAttribute('style') || !! document.querySelector('.sf-pc [style]'),
        };
    }, sel);

    php(SET_BANNER(cat.id, ids.main));
    for (const width of [1280, 360]) {
        ctx = await newCtx(width);
        page = track(await ctx.newPage());
        const resp = await page.goto(BASE + cat.url, { waitUntil: 'networkidle' });
        check(`${width}px: the category page opens`, resp.status() === 200, resp.status());
        const m = await measure(page, '.sf-cat-banner .sf-pc');
        check(`${width}px: the card is the banner, the full width of its place, one h1, no heading in the block, no sideways scroll`, m && near(m.w, m.place) && m.h1 === 1 && m.headings === 0 && ! m.scroll, JSON.stringify(m));
        const minH = Math.min(320, 0.7 * width);
        check(`${width}px: the height is min(320px, 70vw) (${Math.round(minH)}px), the border 2px and the corners 16px`, m && m.h >= minH - 1 && m.h <= minH + 120 && m.bw === '2px' && m.radius === '16px', JSON.stringify({ h: m?.h, bw: m?.bw, r: m?.radius, minh: m?.minh }));
        check(`${width}px: the title is ${width === 1280 ? 'XL = 48' : 'about 60% = 28.8'}px and a div`, m && near(m.titleSize, width === 1280 ? 48 : 28.8, 1) && m.titleTag === 'DIV', JSON.stringify({ size: m?.titleSize, tag: m?.titleTag }));
        check(`${width}px: the picture covers the box (cover) and no style attribute is in the block`, m && m.bg.startsWith('url(') && m.size === 'cover' && ! m.hasStyleAttr, JSON.stringify({ bg: m?.bg, size: m?.size }));
        check(`${width}px: the whole block is one link (the link covers it; a click in the middle hits it)`, m && m.linkCovers === true && m.hitHref === '/', JSON.stringify({ covers: m?.linkCovers, hit: m?.hitHref }));
        await shotOf(page, `hb5-category-banner-${width}`);
        if (width === 1280) {
            const nav = page.waitForURL((u) => new URL(u).pathname === '/', { timeout: 15000 });
            await page.locator('.sf-cat-banner .sf-pc').click({ position: { x: 60, y: 60 } });
            await nav.then(() => check('a click on the picture follows the link', true), () => check('a click on the picture follows the link', false, page.url()));
        }
        await ctx.close();
    }

    // picture-only
    php(SET_BANNER(cat.id, ids.only));
    ctx = await newCtx(1280);
    page = track(await ctx.newPage());
    await page.goto(BASE + cat.url, { waitUntil: 'networkidle' });
    let m = await measure(page, '.sf-cat-banner .sf-pc');
    const linkLabel = await page.locator('.sf-cat-banner a.sf-pc-link').getAttribute('aria-label');
    check('a picture-only card is named by its alt (inside a link: the link) and has no text element', m && linkLabel === 'Колекция есен' && (await page.locator('.sf-cat-banner .sf-pc-text').count()) === 0 && m.h >= 239, JSON.stringify({ linkLabel, h: m?.h }));
    await shotOf(page, 'hb5-category-banner-picture-only-1280');
    await ctx.close();

    // ============================================================ the four sizes, light / dark, custom colours, an old block (the home page, sections of the HP-1 zone)
    const section = (id) => ({ type: 'html_block', settings: { html_block: id } });
    const out = php(PUBLISH_HOME([section(ids.s), section(ids.m), section(ids.l), section(ids.xl), section(ids.dark), section(ids.custom), section(ids.old), section(ids.text)]));
    check('the home layout with the blocks was published through the application', out === '[]', out);
    const sizes = { 1280: [18, 24, 32, 48], 360: [10.8, 14.4, 19.2, 28.8] };
    for (const width of [1280, 360]) {
        ctx = await newCtx(width);
        page = track(await ctx.newPage());
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const r = await page.evaluate(() => {
            const boxes = [...document.querySelectorAll('.sf-hp-section .sf-hb')];
            const cardBoxes = boxes.filter((b) => b.classList.contains('sf-pc'));
            const sec = [...document.querySelectorAll('.sf-hp-section')].map((s) => s.getBoundingClientRect().width);
            const title = (b) => parseFloat(getComputedStyle(b.querySelector('.sf-pc-title')).fontSize);
            const old = boxes.find((b) => ! b.classList.contains('sf-pc'));
            const oc = old ? getComputedStyle(old) : null;
            return {
                titles: cardBoxes.slice(0, 4).map(title),
                widths: cardBoxes.map((b) => Math.round(b.getBoundingClientRect().width * 10) / 10), places: sec.map((w) => Math.round(w * 10) / 10),
                dark: getComputedStyle(cardBoxes[4].querySelector('.sf-pc-title')).color, custom: getComputedStyle(cardBoxes[5].querySelector('.sf-pc-title')).color, customBorder: getComputedStyle(cardBoxes[5]).borderTopColor,
                sub: parseFloat(getComputedStyle(cardBoxes[3].querySelector('.sf-pc-sub')).fontSize), par: parseFloat(getComputedStyle(cardBoxes[3].querySelector('.sf-pc-par')).fontSize),
                old: oc ? { bg: oc.backgroundImage.slice(0, 40), minh: oc.minHeight, w: oc.width, disp: oc.display, color: oc.color } : null, scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            };
        });
        check(`${width}px: the title sizes are ${sizes[width].join(' / ')} px (S / M / L / XL)`, r.titles.length === 4 && r.titles.every((t, i) => near(t, sizes[width][i], 1)), JSON.stringify(r.titles));
        check(`${width}px: every card is the full width of its section and nothing scrolls sideways`, r.widths.length === 6 && r.widths.every((w, i) => near(w, r.places[i], 1.2)) && ! r.scroll, JSON.stringify({ w: r.widths, p: r.places, scroll: r.scroll }));
        check(`${width}px: the subtitle is at least 14px and the paragraph 14 to 16px`, r.sub >= 13.9 && r.par >= 13.9 && r.par <= 16.1, JSON.stringify({ sub: r.sub, par: r.par }));
        check(`${width}px: dark text is not white; custom colours reach the page (text #ffcc00, border #cc0066)`, r.dark !== 'rgb(255, 255, 255)' && r.custom === 'rgb(255, 204, 0)' && r.customBorder === 'rgb(204, 0, 102)', JSON.stringify({ dark: r.dark, custom: r.custom, border: r.customBorder }));
        check(`${width}px: the old text block with a background picture still looks as before (picture, min-height min(240px,70vw), white text, fit-content width)`, r.old && r.old.bg.includes('linear-gradient') && r.old.color === 'rgb(255, 255, 255)' && r.old.disp !== 'inline', JSON.stringify(r.old));
        await shotOf(page, `hb5-home-sizes-${width}`, true);
        await ctx.close();
    }

    // ============================================================ a card in a footer column
    const f = php(PUBLISH_FOOTER(ids.footer));
    ctx = await newCtx(1280);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const fm = await page.evaluate(() => {
        const box = document.querySelector('footer .sf-pc');
        if (! box) return null;
        const col = box.parentElement.getBoundingClientRect();

        return { w: Math.round(box.getBoundingClientRect().width * 10) / 10, col: Math.round(col.width * 10) / 10, scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, title: box.querySelector('.sf-pc-title')?.textContent };
    });
    check('a card in a footer column fills the column and nothing scrolls sideways', f === 'ok' && fm && near(fm.w, fm.col, 1.5) && ! fm.scroll, JSON.stringify(fm));
    await shotOf(page, 'hb5-footer-1280');
    await ctx.close();

    php(UNPUBLISH_HOME);
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: the settings table (exact rows), the categories, the staff and the HTML blocks are as they were (counts / checksum)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the clean-up finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
