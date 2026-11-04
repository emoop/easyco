// A REAL-BROWSER check of HP-6: the «Hero слайдер» component of the «Начална» zone (HTML-block slides, CSS-only cross-fade with @keyframes, a separate phone block per slide, a pause control without JavaScript).
//   - the Design screen at 1280px and 360px: the fields, the seconds hidden when autoplay is off, the settings ONE per row, a slide as two tabs (desktop / phone), info icons, no sideways scroll;
//   - the box has the chosen ratio (±2px): 21:9 at 1440px, 4:5 at 390px;
//   - slide 1 is visible at the first paint and the others are not; the visible slide changes after ≈ `seconds` and the ORDER is right (N = 3: 1 → 2 → 3 → 1); a faded-out slide is `visibility: hidden` (no link of it is focusable);
//     the Tab key reaches the pause checkbox, the track and the link of the VISIBLE slide only;
//   - `:hover` and the checked pause freeze the animation (and let go again); the label's name goes «Пауза» → «Пуск»;
//   - the desktop blocks are shown from 48rem and the phone blocks below it; the pictures of the hidden copy are NOT downloaded (network log): at 390px the desktop-only pictures, at 1440px the phone-only ones;
//   - with `prefers-reduced-motion: reduce`, with autoplay off and with one slide there is no animation; the first two are a manual row (scroll-snap) with the arrows of the shared carousel; no pause control;
//   - with every script blocked the hero still changes slides (the CSS animation) and the pause checkbox works; nothing scrolls the page sideways; no console errors.
// NOT part of `php artisan test`: run by hand against a dev site. It creates ONE throw-away staff member and 5 «Снимка с текст» blocks with 5 generated pictures (files in storage/app/public/uploads/2026/11, hp6-audit-*), through the
// application's own models; the design changes only through HomeDrafts. At the end everything created is removed (rows AND files), the token stylesheet is rebuilt, every `site_settings` row (the owner's published home layout
// and draft included) is put back EXACTLY by id and the counts, the checksum, the published layout's own checksum and the token file of the stored hash are verified. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hp-6.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hp-6.mjs');
    process.exit(2);
}

OUT && mkdirSync(OUT, { recursive: true });

// a non-zero exit OR an empty answer is an error, with the tail of both streams. NOTE: the code is sent as ONE line (newlines collapsed): no `//` comment inside a PHP template
const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') {
        throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900));
    }
    return out.split('\n').pop();
};

const EMAIL = `hp6-${Date.now()}@example.test`;
const PASSWORD = 'Hp6-' + Math.random().toString(36).slice(2) + 'Aa1!';
const b64 = (value) => Buffer.from(JSON.stringify(value)).toString('base64');

// every site_settings row, the published home layout's own checksum, the counts of everything this script creates, and whether the token stylesheet the stored settings name is on disk
const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$published = DB::table('site_settings')->where('key', 'design.home.global.published')->first();
$compiled = json_decode((string) DB::table('site_settings')->where('key', 'appearance.compiled')->value('value'), true);
$hash = is_array($compiled) ? ($compiled['hash'] ?? null) : null;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'published' => $published ? [$published->id, md5((string) $published->value)] : null, 'tokens_file' => $hash === null ? null : file_exists(storage_path('app/public/appearance/tokens-'.$hash.'.css')), 'staff' => DB::table('staff')->count(), 'html_blocks' => DB::table('html_blocks')->count(), 'catalog_media' => DB::table('catalog_media')->count(), 'files' => count(glob(storage_path('app/public/uploads/2026/11/hp6-audit-*')))]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/hp6_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;

// settings back by id, everything created removed (rows and files), the token stylesheet rebuilt (it keeps only the current and the previous file), settings once more
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hp6_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
DB::table('html_blocks')->where('title', 'like', 'HP6 audit%')->delete();
DB::table('catalog_media')->where('original_name', 'like', 'hp6-audit-%')->delete();
foreach (glob(storage_path('app/public/uploads/2026/11/hp6-audit-*')) as $f) { unlink($f); }
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Header\AppearanceRecompiler::class)->run(app(App\Settings\Contracts\SiteSettingsRepository::class));
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE = String.raw`
use Illuminate\Support\Facades\DB;
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel; use App\Content\HtmlBlocks\HtmlBlockModel;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'HP6 Audit', $role));
$dir = storage_path('app/public/uploads/2026/11');
if (! is_dir($dir)) { mkdir($dir, 0775, true); }
$pics = ['d1' => [1320, 566, [200, 60, 60]], 'd2' => [1320, 566, [60, 120, 200]], 'd3' => [1320, 566, [40, 160, 90]], 'm1' => [358, 448, [230, 140, 30]], 'm3' => [358, 448, [140, 60, 170]]];
$ids = [];
foreach ($pics as $name => [$w, $h, $rgb]) {
  $variants = [];
  foreach (['thumbnail' => 400, 'medium' => 900, 'large' => 1600] as $tier => $max) {
    $tw = min($w, $max); $th = (int) round($h * $tw / $w);
    $img = imagecreatetruecolor($tw, $th); imagefill($img, 0, 0, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
    $c = imagecolorallocate($img, 255, 255, 255); imagerectangle($img, 2, 2, $tw - 3, $th - 3, $c); imageline($img, 0, 0, $tw - 1, $th - 1, $c); imageline($img, 0, $th - 1, $tw - 1, 0, $c);
    imagewebp($img, "$dir/hp6-audit-$name-$tier.webp", 80);
    $variants[] = ['tier' => $tier, 'width' => $tw, 'height' => $th, 'quality' => 80, 'path' => "uploads/2026/11/hp6-audit-$name-$tier.webp"];
  }
  $ids[$name] = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/11/hp6-audit-$name.png", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "hp6-audit-$name.png", 'size_bytes' => 2048, 'width' => $w, 'height' => $h])->id;
}
$card = fn (string $title, string $pic, string $link) => HtmlBlockModel::query()->create(['title' => 'HP6 audit '.$title, 'kind' => 'card', 'card' => ['picture' => $ids[$pic], 'title' => $title, 'subtitle' => '', 'text' => '', 'link' => $link, 'alt' => '']])->id;
echo json_encode(['d1' => $card('Slide 1 desktop', 'd1', '/hp6-d1'), 'd2' => $card('Slide 2', 'd2', '/hp6-d2'), 'd3' => $card('Slide 3 desktop', 'd3', '/hp6-d3'), 'm1' => $card('Slide 1 phone', 'm1', '/hp6-m1'), 'm3' => $card('Slide 3 phone', 'm3', '/hp6-m3')]);`;

// the Design screen starts from the working copy, else from the published layout (which may already hold a hero: only one is allowed): a throw-away one-heading working copy
const DRAFT = String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => [['type' => 'heading', 'settings' => ['text' => 'HP6 audit']]]]);
HomeDrafts::saveDraft($clean);
echo json_encode($errors);`;

const PUBLISH = (sections) => String.raw`
use App\Storefront\Home\HomeValidator; use App\Storefront\Home\HomeDrafts;
[$clean, $errors] = HomeValidator::validate(['spacing' => '16', 'sections' => json_decode(base64_decode('${b64(sections)}'), true)]);
HomeDrafts::publish($clean);
echo json_encode($errors);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const measurements = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, t = 2) => Math.abs(a - b) <= t;

let before = null;
let created = false;
let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    // a run that hangs must not leave the owner's layout replaced: after 8 minutes the data is put back and the run stops
    watchdog = setTimeout(() => { try { php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 8 minutes; the data was put back'); process.exit(3); }, 8 * 60 * 1000);
    const made = JSON.parse(php(CREATE));
    check('the throw-away staff member, 5 pictures and 5 «Снимка с текст» blocks were created', Object.values(made).every((id) => id > 0), JSON.stringify(made));
    check('a throw-away working copy (one heading) for the Design screen', php(DRAFT) === '[]');
    check('before the run the token stylesheet of the stored settings is on disk (so a restore can be judged)', before.tokens_file === true, JSON.stringify(before.tokens_file));

    const newCtx = async (width, extra = {}) => { const c = await browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}), ...extra }); c.setDefaultTimeout(20000); return c; };
    const track = (p) => { p.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200))); p.on('response', (r) => { if (r.status() >= 500) consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); }); return p; };
    const shotOf = async (target, name) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await target.screenshot({ path: p }); } };
    const sideways = (p) => p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);

    // ============================================================ the Design screen: 1280px and 360px
    let ctx = await newCtx(1280);
    let page = track(await ctx.newPage());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    await page.goto(BASE + '/admin/design?zone=home', { waitUntil: 'networkidle' });
    check('the component is in the palette', (await page.locator('.ds-chip-add', { hasText: 'Hero слайдер' }).count()) === 1);
    await page.locator('.ds-chip-add', { hasText: 'Hero слайдер' }).click();
    await page.waitForSelector('.ds-props', { timeout: 30000 });
    await sleep(1500);
    const props = page.locator('.ds-props');
    await props.getByRole('button', { name: 'Добави слайд' }).click();
    await sleep(1500);
    let text = await props.innerText();
    check('the fields are there: automatic change, seconds, the two proportions, the slides', ['Автоматична смяна', 'Секунди на слайд', 'Пропорция на компютър', 'Пропорция на телефон', 'Слайдове', 'Блок за компютър'].every((t) => text.includes(t)), text.replace(/\s+/g, ' ').slice(0, 400));
    check('the recommended picture sizes are shown (the numbers of HeroGeometry: 1320 × 570 and 360 × 450 by default)', /1320 × 570 px, 2× за остри екрани: 2640 × 1130/.test(text) && /360 × 450 px, 2×: 720 × 900/.test(text), text.replace(/\s+/g, ' ').slice(-300));
    check('the explanations are info icons (no helper paragraph under a field)', (await props.locator('[aria-describedby^="ds-hint-"]').count()) >= 6, String(await props.locator('[aria-describedby^="ds-hint-"]').count()));
    const topOf = async (label) => props.locator('.fi-fo-field', { has: page.locator('label', { hasText: label }) }).first().evaluate((el) => Math.round(el.getBoundingClientRect().top));
    const pairs = async () => ({ autoplay: await topOf('Автоматична смяна'), seconds: await topOf('Секунди на слайд'), ratio: await topOf('Пропорция на компютър'), phone: await topOf('Пропорция на телефон'), block: await topOf('Блок за компютър') });
    let t = await pairs();
    check('1280px: the settings are ONE per row (each under the one before)', t.seconds > t.autoplay + 20 && t.ratio > t.seconds + 20 && t.phone > t.ratio + 20 && t.block > t.phone + 20, JSON.stringify(t));
    check('1280px: nothing scrolls the page sideways', ! (await sideways(page)));
    await shotOf(page, 'hp6-panel-1280');
    // a slide is two tabs: «Компютър» (its block) and «Телефон» (its optional phone block), one selector each
    const tabs = await props.getByRole('tab').allInnerTexts();
    check('a slide is two tabs, «Компютър» and «Телефон»; the phone selector is in the second one', tabs.includes('Компютър') && tabs.includes('Телефон') && ! (await props.innerText()).includes('Блок за телефон (по избор)'), JSON.stringify(tabs));
    await props.getByRole('tab', { name: 'Телефон' }).click();
    await sleep(600);
    check('the «Телефон» tab shows the optional phone block selector (and hides the desktop one)', (await props.innerText()).includes('Блок за телефон (по избор)') && ! (await props.innerText()).includes('Блок за компютър'), '');
    await props.getByRole('tab', { name: 'Компютър' }).click();
    await sleep(600);
    // the seconds are hidden while the slides do not change by themselves
    const autoplay = props.locator('.fi-fo-field', { has: page.locator('label', { hasText: 'Автоматична смяна' }) }).first().locator('button[role=switch]');
    await autoplay.click();
    await sleep(1800);
    text = await props.innerText();
    check('autoplay off: «Секунди на слайд» is hidden, the proportions stay', ! text.includes('Секунди на слайд') && text.includes('Пропорция на компютър'), text.replace(/\s+/g, ' ').slice(0, 200));
    await autoplay.click();
    await sleep(1500);
    check('autoplay on again: the seconds are back', (await props.innerText()).includes('Секунди на слайд'));

    await page.setViewportSize({ width: 360, height: 800 });
    await sleep(1200);
    t = await pairs();
    check('360px: the settings stay one per row', t.seconds > t.autoplay + 20 && t.phone > t.ratio + 20, JSON.stringify(t));
    check('360px: nothing scrolls the page sideways', ! (await sideways(page)));
    await shotOf(page, 'hp6-panel-360');
    await ctx.close();

    // ============================================================ the storefront
    const blk = (id, phone = null) => ({ block: id, block_phone: phone });
    const hero = (settings = {}, slides = [blk(made.d1, made.m1), blk(made.d2), blk(made.d3, made.m3)]) => ({ type: 'hero', settings: { seconds: '3', ratio: '21:9', ratio_phone: '4:5', slides, ...settings } });
    const publish = (section, name) => check(`${name} published through the application`, php(PUBLISH([section])) === '[]');

    // the sampler: opacity and visibility of every slide every 100ms, with the time since the animation started
    const sample = (p, ms) => p.evaluate(async (limit) => {
        const slides = [...document.querySelectorAll('.sf-hs-slide')];
        const a = slides[0].getAnimations()[0];
        const out = [];
        const start = performance.now();
        while (performance.now() - start < limit) {
            out.push({ c: a ? Math.round(a.currentTime) : null, t: a ? Math.round(document.timeline.currentTime - a.startTime) / 1000 : null, o: slides.map((s) => Math.round(parseFloat(getComputedStyle(s).opacity) * 100) / 100), v: slides.map((s) => getComputedStyle(s).visibility) });
            await new Promise((r) => setTimeout(r, 100));
        }
        return out;
    }, ms);
    const dominant = (row) => row.o.indexOf(Math.max(...row.o));
    const playState = (p) => p.evaluate(() => document.querySelector('.sf-hs-slide').getAnimations()[0]?.playState ?? 'none');

    // ------------------------------------------------------------ 1. timing, order and first paint at 1440px (3 slides, 3 seconds)
    publish(hero(), 'the hero (3 slides, 3 s, 21:9 / 4:5, a phone block on slides 1 and 3)');
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    const first = await page.evaluate(() => { const s = [...document.querySelectorAll('.sf-hs-slide')]; const a = s[0].getAnimations()[0]; return { count: s.length, o: s.map((x) => getComputedStyle(x).opacity), v: s.map((x) => getComputedStyle(x).visibility), elapsed: a ? (document.timeline.currentTime - a.startTime) / 1000 : null, anims: document.getAnimations().length }; });
    check('first paint: slide 1 is visible (opacity 1), the other two are hidden; the animation has just started (no delay, no flash)', first.count === 3 && first.o[0] === '1' && first.v[0] === 'visible' && first.v[1] === 'hidden' && first.v[2] === 'hidden' && first.elapsed !== null && first.elapsed < 2 && first.anims >= 3, JSON.stringify(first));
    const rows = await sample(page, 11500);
    const changes = [];
    rows.forEach((r, i) => { if (i > 0 && dominant(r) !== dominant(rows[i - 1])) changes.push([dominant(r), r.t]); });
    measurements.timing = changes;
    check('the order is 1 → 2 → 3 → 1', JSON.stringify(changes.slice(0, 3).map((c) => c[0])) === '[1,2,0]', JSON.stringify(changes));
    check('the visible slide changes after ≈ show (3 s): the cross-fade is centred at 2.85 s, 5.85 s, 8.85 s (±0.5 s)', changes.length >= 3 && near(changes[0][1], 2.85, 0.5) && near(changes[1][1], 5.85, 0.5) && near(changes[2][1], 8.85, 0.5), JSON.stringify(changes));
    check('a slide with opacity 0 is never `visibility: visible` (so it is not clickable and not in the tab order)', rows.every((r) => r.o.every((o, i) => o > 0 || r.v[i] === 'hidden')), '');
    check('at every moment the opacities of the slides add up to about 1 (a cross-fade, never a dip or a flash)', rows.every((r) => near(r.o.reduce((a, b) => a + b, 0), 1, 0.12)), JSON.stringify(rows.filter((r) => ! near(r.o.reduce((a, b) => a + b, 0), 1, 0.12)).slice(0, 2)));
    await ctx.close();

    // ------------------------------------------------------------ 2. the box, the blocks of the visible slide, the focusable links, the screenshots
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const box = await page.evaluate(() => { const t = document.querySelector('.sf-hs-track').getBoundingClientRect(); const s = document.querySelector('.sf-hs-slide .sf-hb').getBoundingClientRect(); return { w: t.width, h: t.height, block: [s.width, s.height], d: getComputedStyle(document.querySelector('.sf-hs-d')).display, m: getComputedStyle(document.querySelector('.sf-hs-m')).display }; });
    check('1440px: the box is 21:9 (±2px)', near(box.h, box.w * 9 / 21, 2), JSON.stringify(box));
    check('1440px: the block fills its slide and the desktop block is shown, the phone block is not', near(box.block[0], box.w, 1) && near(box.block[1], box.h, 1) && box.d !== 'none' && box.m === 'none', JSON.stringify(box));
    check('1440px: nothing scrolls the page sideways', ! (await sideways(page)));
    await shotOf(page.locator('section.sf-hs'), 'hp6-1440-first');
    // the links that can be reached at 1.5 s, 4.5 s and 7.5 s: exactly the one of the visible slide (the phone copies are display:none)
    await page.reload({ waitUntil: 'domcontentloaded' });
    const reach = [];
    const t0 = Date.now();
    for (const at of [1.5, 4.5, 7.5]) {
        await sleep(Math.max(0, at * 1000 - (Date.now() - t0)));
        reach.push(await page.evaluate(() => [...document.querySelectorAll('.sf-pc-link')].filter((a) => a.offsetParent !== null && getComputedStyle(a).visibility === 'visible').map((a) => a.getAttribute('href'))));
        at === 4.5 && await shotOf(page.locator('section.sf-hs'), 'hp6-1440-second');
    }
    measurements.reach = reach;
    check('only the link of the visible slide can be focused (slide 1, 2, 3 in turn)', JSON.stringify(reach) === JSON.stringify([['/hp6-d1'], ['/hp6-d2'], ['/hp6-d3']]), JSON.stringify(reach));
    await ctx.close();

    // the Tab key: pause checkbox, track, the visible slide's link, and nothing of the hidden slides (a slow hero so the slide does not change meanwhile)
    publish(hero({ seconds: '10' }), 'the same hero at 10 s per slide');
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const order = [];
    for (let i = 0; i < 60; i++) {
        await page.keyboard.press('Tab');
        const at = await page.evaluate(() => { const e = document.activeElement; return { inside: !! e.closest('.sf-hs'), name: e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).split(' ')[0] : '') + (e.getAttribute('href') ? '[' + e.getAttribute('href') + ']' : '') }; });
        if (at.inside) order.push(at.name);
        else if (order.length) break;
    }
    measurements.tab = order;
    check('the Tab key reaches the pause checkbox, the track and the link of the visible slide, and no link of a hidden slide', JSON.stringify(order) === JSON.stringify(['input.sf-hs-toggle', 'ul.sf-hs-track', 'a.sf-pc-link[/hp6-d1]']), JSON.stringify(order));
    await ctx.close();

    // ------------------------------------------------------------ 3. pause: hover, focus and the checked checkbox freeze the animation, and let go
    publish(hero(), 'the hero at 3 s per slide again');
    ctx = await newCtx(1440);
    page = track(await ctx.newPage());
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.mouse.move(600, 10);
    await sleep(600);
    const rect = await page.evaluate(() => { const r = document.querySelector('.sf-hs-track').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    await page.mouse.move(rect.x, rect.y);
    await sleep(400);
    const h1 = (await sample(page, 200)).at(-1);
    const state1 = await playState(page);
    await sleep(4500);
    const h2 = (await sample(page, 200)).at(-1);
    check(':hover freezes the animation (paused, the same slide and the same moment after 4.5 s)', state1 === 'paused' && dominant(h1) === dominant(h2) && near(h1.c, h2.c, 60), JSON.stringify({ state1, h1, h2 }));
    await page.mouse.move(600, 10);
    await sleep(300);
    const resumed = await sample(page, 3600);
    check('when the pointer leaves it runs again (the visible slide changes within one slide time)', new Set(resumed.map(dominant)).size >= 2, JSON.stringify([...new Set(resumed.map(dominant))]));

    const label = page.locator('label.sf-hs-pause');
    check('the pause control is a round white control in a corner of the hero, named «Пауза»', (await label.innerText()).trim() === 'Пауза' && await label.evaluate((el) => { const s = el.closest('.sf-hs').getBoundingClientRect(); const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.right <= s.right + 1 && r.bottom <= s.bottom + 1 && r.left > s.left + s.width / 2 && r.top > s.top + s.height / 2 && cs.borderRadius !== '0px' && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' && r.height >= 44; }));
    await label.click();
    await page.evaluate(() => document.activeElement.blur());
    await page.mouse.move(600, 10);
    await sleep(500);
    const c1 = (await sample(page, 200)).at(-1);
    const stateC = await playState(page);
    await sleep(4500);
    const c2 = (await sample(page, 200)).at(-1);
    check('the checked pause freezes it with the pointer away and nothing focused; the label now says «Пуск»', stateC === 'paused' && dominant(c1) === dominant(c2) && near(c1.c, c2.c, 60) && (await label.innerText()).trim() === 'Пуск', JSON.stringify({ stateC, c1, c2, name: (await label.innerText()).trim() }));
    await shotOf(page.locator('section.sf-hs'), 'hp6-1440-paused');
    await label.click();
    await page.evaluate(() => document.activeElement.blur());
    await page.mouse.move(600, 10);
    await sleep(300);
    const un = await sample(page, 3600);
    check('unchecked it runs again and says «Пауза»', new Set(un.map(dominant)).size >= 2 && (await label.innerText()).trim() === 'Пауза', JSON.stringify([...new Set(un.map(dominant))]));
    await ctx.close();

    // ------------------------------------------------------------ 4. the phone (390px): the phone box, the phone blocks, the network log of the hidden copies
    publish(hero({ seconds: '10' }), 'the hero at 10 s again (for the network logs)');
    const pictures = async (width) => {
        const c = await newCtx(width);
        const p = track(await c.newPage());
        const asked = new Set();
        p.on('request', (r) => { const m = /hp6-audit-([dm][13]?[0-9]?)-(?:thumbnail|medium|large)\./.exec(r.url()); m && asked.add(m[1]); });
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        await sleep(1500);
        const info = await p.evaluate(() => { const t = document.querySelector('.sf-hs-track').getBoundingClientRect(); const slides = [...document.querySelectorAll('.sf-hs-slide')].map((s) => ({ d: getComputedStyle(s.querySelector('.sf-hs-d')).display, m: s.querySelector('.sf-hs-m') ? getComputedStyle(s.querySelector('.sf-hs-m')).display : null })); return { w: t.width, h: t.height, slides, sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 }; });
        return { c, p, asked: [...asked].sort(), info };
    };
    const phone = await pictures(390);
    measurements.pictures390 = phone.asked;
    check('390px: the box is 4:5 (±2px)', near(phone.info.h, phone.info.w * 5 / 4, 2), JSON.stringify(phone.info));
    check('390px: the phone blocks show where there is one (slides 1 and 3) and the desktop block of slide 2 (it has no phone block) shows too', JSON.stringify(phone.info.slides) === JSON.stringify([{ d: 'none', m: 'block' }, { d: 'block', m: null }, { d: 'none', m: 'block' }]), JSON.stringify(phone.info.slides));
    check('390px network log: the desktop-only pictures (slides 1 and 3) are NOT downloaded; the phone pictures and the one picture of slide 2 are', JSON.stringify(phone.asked) === JSON.stringify(['d2', 'm1', 'm3']), JSON.stringify(phone.asked));
    check('390px: nothing scrolls the page sideways', ! phone.info.sideways);
    await shotOf(phone.p.locator('section.sf-hs'), 'hp6-390-first');
    const lbl = phone.p.locator('label.sf-hs-pause');
    await lbl.click();
    await sleep(300);
    await shotOf(phone.p.locator('section.sf-hs'), 'hp6-390-paused');
    await phone.c.close();
    const desktop = await pictures(1440);
    measurements.pictures1440 = desktop.asked;
    check('1440px network log: the phone-only pictures are NOT downloaded; the three desktop pictures are (all slides are rendered, only the first is visible)', JSON.stringify(desktop.asked) === JSON.stringify(['d1', 'd2', 'd3']), JSON.stringify(desktop.asked));
    await desktop.c.close();

    // ------------------------------------------------------------ 5. the manual row: reduced motion, autoplay off, one slide
    const manual = async (name, ctxOptions, section) => {
        publish(section, name);
        const c = await newCtx(1440, ctxOptions);
        const p = track(await c.newPage());
        await p.goto(BASE + '/', { waitUntil: 'networkidle' });
        await sleep(600);
        const r = await p.evaluate(() => { const tr = document.querySelector('.sf-hs-track'); const arrows = [...document.querySelectorAll('.sf-car-arrow[data-car-step]')]; return { anims: document.getAnimations().filter((a) => a.effect?.target?.closest?.('.sf-hs')).length, display: getComputedStyle(tr).display, overflowX: getComputedStyle(tr).overflowX, snap: getComputedStyle(tr).scrollSnapType, scrollable: tr.scrollWidth > tr.clientWidth, vis: [...document.querySelectorAll('.sf-hs-slide')].map((s) => getComputedStyle(s).visibility), pause: document.querySelector('.sf-hs-pause') ? getComputedStyle(document.querySelector('.sf-hs-pause')).display : 'absent', arrows: arrows.map((a) => [a.hidden, a.disabled]), tab: tr.getAttribute('tabindex'), width: tr.clientWidth }; });
        return { c, p, r };
    };

    let m = await manual('the hero with reduced motion requested', { reducedMotion: 'reduce' }, hero());
    measurements.reduced = m.r;
    check('reduced motion: no animation, the track is a scroll-snap row, every slide is visible, no pause control, the track is a tab stop', m.r.anims === 0 && m.r.display === 'flex' && m.r.overflowX === 'auto' && m.r.snap.startsWith('x') && m.r.scrollable && m.r.vis.every((v) => v === 'visible') && (m.r.pause === 'none' || m.r.pause === 'absent') && m.r.tab === '0', JSON.stringify(m.r));
    check('reduced motion: the arrows of the shared carousel are shown by the script, «previous» is disabled at the start', m.r.arrows.length === 2 && m.r.arrows.every((a) => ! a[0]) && m.r.arrows[0][1] === true && m.r.arrows[1][1] === false, JSON.stringify(m.r.arrows));
    await shotOf(m.p.locator('section.sf-hs'), 'hp6-1440-reduced');
    await m.p.click('.sf-car-next');
    await sleep(1200);
    const after = await m.p.evaluate(() => { const tr = document.querySelector('.sf-hs-track'); return { left: tr.scrollLeft, width: tr.clientWidth, prev: document.querySelector('.sf-car-prev').disabled }; });
    check('reduced motion: the «next» arrow moves by one slide (the whole box) and «previous» is enabled', near(after.left, after.width, 4) && after.prev === false, JSON.stringify(after));
    await m.p.evaluate(() => { document.querySelector('.sf-hs-track').scrollLeft = 0; });
    await sleep(600);
    await m.p.focus('.sf-hs-track');
    await m.p.keyboard.press('ArrowRight');
    await sleep(900);
    check('reduced motion: the keyboard arrow scrolls the focused track', await m.p.evaluate(() => document.querySelector('.sf-hs-track').scrollLeft > 10));
    await m.c.close();

    m = await manual('the hero with autoplay off', {}, hero({ autoplay: false }));
    check('autoplay off: no animation, a manual scroll-snap row with the arrows, no pause control in the page', m.r.anims === 0 && m.r.display === 'flex' && m.r.scrollable && m.r.vis.every((v) => v === 'visible') && m.r.pause === 'absent' && m.r.arrows.length === 2 && m.r.arrows.every((a) => ! a[0]), JSON.stringify(m.r));
    await m.c.close();

    m = await manual('the hero with ONE slide', {}, hero({}, [blk(made.d1, made.m1)]));
    check('one slide: no animation, nothing to scroll, no arrows, no pause control, the slide is visible and is a tab-free track', m.r.anims === 0 && ! m.r.scrollable && m.r.arrows.length === 0 && m.r.pause === 'absent' && m.r.vis.length === 1 && m.r.vis[0] === 'visible' && m.r.tab === null, JSON.stringify(m.r));
    await m.c.close();

    // ------------------------------------------------------------ 6. JavaScript disabled: the CSS still changes the slides and the pause checkbox works
    publish(hero(), 'the hero at 3 s per slide, for a visitor without JavaScript');
    // (a context with JavaScript switched off crashes Playwright's own measuring, so every script of the page is blocked instead: the hero has none and the shared carousel's script cannot run either)
    ctx = await newCtx(1440);
    await ctx.route('**/*.js', (route) => route.abort());
    page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'load' });
    const nojs = await sample(page, 3600);
    check('with every script blocked the hero still changes slides (slide 1 first, then slide 2) and nothing is hidden by a script', dominant(nojs[0]) === 0 && dominant(nojs.at(-1)) === 1, JSON.stringify([dominant(nojs[0]), dominant(nojs.at(-1))]));
    await page.click('label.sf-hs-pause');
    await page.evaluate(() => document.activeElement.blur());
    await page.mouse.move(5, 5);
    await sleep(400);
    const n1 = (await sample(page, 200)).at(-1);
    await sleep(4000);
    const n2 = (await sample(page, 200)).at(-1);
    check('with every script blocked the pause checkbox freezes it (the same moment 4 s later)', (await playState(page)) === 'paused' && near(n1.c, n2.c, 60) && dominant(n1) === dominant(n2), JSON.stringify({ n1, n2 }));
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    clearTimeout(watchdog);

    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything is back: every settings row (the published home layout and the draft included, by id and checksum), the staff, the blocks, the media assets and the files are as they were; the token stylesheet of the stored settings is on disk', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the clean-up finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await Promise.race([browser.close(), sleep(10000)]);

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, measurements, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
