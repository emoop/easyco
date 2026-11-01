// A REAL-BROWSER run of CD-1c: the category filters in the new look — the desktop BAR (dropdown controls with a bottom line, one column of links in each panel, a choice applies at once, «Изчисти» only with a
// selection, «Брой продукти: N» beside the title) and the phone DRAWER (a list of rows, one filter per screen, a delicate «Филтрирай»). NOT part of `php artisan test`: run by hand against a dev site whose data may
// be changed. It creates a throw-away category («cd1c-cat») with 60 products (colour × size variations, a product-level material), three filterable attributes (Colour = circles, Size = text links, Material =
// a list of 9 values) and BACKS UP the `site_settings` table and the `appearance/` files and checksums of the catalog tables first; at the end it removes everything it created and verifies the checksums.
// It measures the look against the written spec and takes screenshots. Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/cd-1c.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node cd-1c.mjs');
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

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/cd1c_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files))]));
echo 'ok';`;

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['settings' => $sum('site_settings'), 'defs' => $sum('catalog_attribute_definitions'), 'values' => $sum('catalog_attribute_values'), 'cats' => $sum('catalog_categories'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'stock' => DB::table('stock_levels')->count(), 'pa' => DB::table('catalog_product_attributes')->count(), 'vv' => DB::table('catalog_variation_attribute_values')->count(), 'pc' => DB::table('catalog_product_categories')->count()]);`;

const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'CD1C категория', 'slug' => 'cd1c-cat', 'created_at' => $now, 'updated_at' => $now]);
$def = function ($code, $name, $display, $values) use ($now) {
  $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => $code, 'name' => $name, 'type' => 'select', 'is_filterable' => 1, 'filter_display' => $display, 'created_at' => $now, 'updated_at' => $now]);
  $ids = [];
  foreach ($values as $i => [$label, $colour]) { $ids[$label] = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $id, 'value' => $label, 'sort_order' => $i, 'colour' => $colour, 'created_at' => $now, 'updated_at' => $now]); }
  return [$id, $ids];
};
[$colour, $c] = $def('cd1c-colour', 'Цвят', 'swatches', [['Червен', '#cc0000'], ['Син', '#0000cc'], ['Зелен', '#00aa44'], ['Жълт', '#e6c200'], ['Черен', '#111111']]);
[$size, $s] = $def('cd1c-size', 'Размер', 'links', [['XS', null], ['S', null], ['M', null], ['L', null], ['XL', null]]);
[$material, $m] = $def('cd1c-material', 'Материя', 'list', [['Памук', null], ['Вълна', null], ['Лен', null], ['Коприна', null], ['Полиестер', null], ['Вискоза', null], ['Кашмир', null], ['Лиоцел', null], ['Еластан', null]]);
$colours = ['Червен', 'Син', 'Зелен', 'Жълт', 'Черен']; $sizes = ['XS', 'S', 'M', 'L', 'XL']; $mats = array_keys($m);
for ($i = 1; $i <= 60; $i++) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => "CD1C продукт $i", 'slug' => "cd1c-$i", 'base_sku' => "CD1C-$i", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($i)]);
  foreach ([[$colours[$i % 5], $sizes[$i % 5]], [$colours[($i + 1) % 5], $sizes[($i + 2) % 5]]] as $k => [$cv, $sv]) {
    $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => $k, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "cd1c-$i-$k"), 'sku' => "CD1C-V$i-$k", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('catalog_variation_attribute_values')->insert([['variation_id' => $vid, 'attribute_definition_id' => $colour, 'attribute_value_id' => $c[$cv], 'created_at' => $now, 'updated_at' => $now], ['variation_id' => $vid, 'attribute_definition_id' => $size, 'attribute_value_id' => $s[$sv], 'created_at' => $now, 'updated_at' => $now]]);
    DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 3, 'created_at' => $now, 'updated_at' => $now]);
  }
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  DB::table('catalog_product_attributes')->insert(['product_id' => $pid, 'attribute_definition_id' => $material, 'is_variation_axis' => 0, 'attribute_value_id' => $m[$mats[$i % 9]], 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
}
app(App\Storefront\Filters\FilterCatalog::class)::forget();
echo json_encode(['cat' => $cat, 'colour' => $colour, 'size' => $size, 'material' => $material, 'c' => $c, 's' => $s, 'm' => $m]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/cd1c_backup.json'), true);
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
$pids = DB::table('catalog_products')->where('slug', 'like', 'cd1c-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'like', 'cd1c-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
DB::table('catalog_categories')->where('slug', 'cd1c-cat')->delete();
app(App\Storefront\Filters\FilterCatalog::class)::forget();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const consoleErrors = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, t = 1.5) => Math.abs(a - b) <= t;
// a click that leaves the page: wait until the address changed, then until the new page is quiet
const nav = async (page, locator) => { const from = page.url(); await locator.click(); await page.waitForFunction((u) => location.href !== u, from, { timeout: 20000 }); await page.waitForLoadState('networkidle'); };
// the category page's layout as the published design holds it (CD-1c.1 checks the side panel); the appearance cache is refreshed in the same call
const LAYOUT = (layout) => String.raw`
use Illuminate\Support\Facades\DB;
DB::table('site_settings')->where('key', 'design.catalog.global')->delete();
DB::table('site_settings')->insert(['key' => 'design.catalog.global', 'value' => json_encode(['layout' => '${layout}', 'side' => 'left']), 'created_at' => now(), 'updated_at' => now()]);
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'layout ${layout}';`;

let before = null;
let backedUp = false;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    backedUp = true;
    const ids = JSON.parse(php(SETUP));
    const URL = '/product-category/cd1c-cat';

    const open = async (width, path, shotName = null) => {
        const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 800 : 1000 }, ignoreHTTPSErrors: true, ...(width < 600 ? { hasTouch: true, isMobile: true } : {}) });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        await page.goto(BASE + path, { waitUntil: 'networkidle' });
        const shot = async (name, clip = null) => { if (OUT) { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, ...(clip ? { clip } : { fullPage: false }) }); } };
        shotName && await shot(shotName);

        return { ctx, page, shot };
    };
    const qs = (page) => decodeURIComponent(new globalThis.URL(page.url()).search);
    const style = (page, selector, props) => page.$eval(selector, (el, ps) => { const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); const o = { w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10, x: Math.round(r.x * 10) / 10, y: Math.round(r.y * 10) / 10 }; for (const p of ps) o[p] = cs[p]; return o; }, props);
    const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

    // ============================================================ 1280: the bar
    let { ctx, page, shot } = await open(1280, URL, 'cd1c-01-bar-closed-1280');
    const head = await page.evaluate(() => { const h = document.querySelector('.sf-cat-head h1'); const c = document.querySelector('.sf-cat-head .sf-count'); const hb = h.getBoundingClientRect(); const cb = c.getBoundingClientRect(); const cs = getComputedStyle(c); return { text: c.textContent.trim(), size: cs.fontSize, color: cs.color, sameLine: Math.abs((hb.bottom) - (cb.bottom)) < 12, count: document.querySelectorAll('.sf-grid .sf-card').length }; });
    check('1280: «Брой продукти: N» beside the title on one line, 13px', /^Брой продукти: 60$/.test(head.text) && head.size === '13px' && head.sameLine, JSON.stringify(head));
    const cols = (await style(page, '.sf-cbar', ['gridTemplateColumns', 'columnGap', 'rowGap', 'display'])).gridTemplateColumns.split(' ').length;
    const barBox = await style(page, '.sf-cbar', ['columnGap', 'rowGap']);
    check('1280: the bar is a grid of 5 columns, 24px between columns and 12px between rows', cols === 5 && barBox.columnGap === '24px' && barBox.rowGap === '12px', JSON.stringify({ cols, barBox }));
    const sum0 = await style(page, '.sf-bctl-sum', ['height', 'borderBottomWidth', 'borderBottomStyle', 'backgroundColor', 'borderRadius', 'paddingLeft', 'fontSize', 'borderBottomColor', 'borderTopWidth']);
    check('1280: a control is 44px high, has only a 1px bottom line, no background, no radius, 14px text', sum0.h === 44 && sum0.borderBottomWidth === '1px' && sum0.borderTopWidth === '0px' && sum0.backgroundColor === 'rgba(0, 0, 0, 0)' && sum0.borderRadius === '0px' && sum0.fontSize === '14px', JSON.stringify(sum0));
    // CD-1c.1: the bottom line of a control is the muted token (darker than the page line token), still 1px
    const tokens = await page.evaluate(() => { const bar = document.querySelector('.sf-cbar'); const tok = (v) => { const p = document.createElement('i'); p.style.borderTopColor = v; bar.append(p); const c = getComputedStyle(p).borderTopColor; p.remove(); return c; }; return { line: tok('var(--sf-pdp-line)'), muted: tok('var(--sf-pdp-muted)') }; });
    const ruleColour = await page.$eval('.sf-bctl-sum', (e) => getComputedStyle(e).borderBottomColor);
    check('1280: the bottom line of a control is the muted grey token, darker than the page line token, 1px', ruleColour === tokens.muted && ruleColour !== tokens.line && sum0.borderBottomWidth === '1px', JSON.stringify({ was: tokens.line, now: ruleColour }));
    const chev = await page.evaluate(() => { const cs = getComputedStyle(document.querySelector('.sf-bctl-sum'), '::after'); return { w: cs.width, h: cs.height, bb: cs.borderBottomWidth, br: cs.borderRightWidth, tf: cs.transform }; });
    check('1280: the chevron is made with CSS: 8px, 1px borders, rotated', chev.w === '8px' && chev.h === '8px' && chev.bb === '1px' && chev.br === '1px' && chev.tf !== 'none', JSON.stringify(chev));
    const first = await page.$$eval('.sf-bctl', (els) => els.map((e) => ({ sort: e.classList.contains('sf-bctl-sort'), text: e.querySelector('.sf-bctl-text').textContent.trim(), weight: getComputedStyle(e.querySelector('.sf-bctl-text')).fontWeight })));
    check('1280: the FIRST control is the sort («Най-нови», weight 500), then Цвят, Размер, Материя', first[0].sort && first[0].text === 'Най-нови' && first[0].weight === '500' && first.slice(1).map((c) => c.text).join('|') === 'Цвят|Размер|Материя', JSON.stringify(first));
    check('1280: no «Филтрирай» and no global «Изчисти» in the bar; the drawer form and the toolbar are not shown', ! (await page.locator('.sf-cbar .sf-fbtn, .sf-cbar .sf-fclear').count()) && ! (await page.locator('.sf-fdrawer').first().isVisible()) && ! (await page.locator('.sf-toolbar').first().isVisible()));
    check('1280: no horizontal scroll', await noOverflow(page));

    // the colour panel (swatches)
    await page.locator('.sf-bctl-sum', { hasText: 'Цвят' }).click();
    const sw = await style(page, '.sf-bctl[open] .sf-bctl-panel', ['position', 'backgroundColor', 'borderTopWidth', 'boxShadow', 'paddingTop', 'zIndex']);
    const ctl = await style(page, '.sf-bctl[open]', []);
    check('1280: the colour panel opens under its control, left aligned, 280px wide, white, 1px border, 16px padding, no shadow, above the grid', sw.w === 280 && sw.position === 'absolute' && near(sw.x, ctl.x, 1) && near(sw.y, ctl.y + ctl.h, 2) && sw.backgroundColor === 'rgb(255, 255, 255)' && sw.borderTopWidth === '1px' && sw.boxShadow === 'none' && sw.paddingTop === '16px' && Number(sw.zIndex) >= 30, JSON.stringify({ sw, ctl }));
    const dots = await page.$$eval('.sf-bctl[open] .sf-bv-dot', (els) => els.map((e) => ({ w: e.getBoundingClientRect().width, h: e.getBoundingClientRect().height })));
    check('1280: the swatches are 28px circles with accessible names', dots.length === 5 && dots.every((d) => d.w === 28 && d.h === 28) && (await page.locator('.sf-bctl[open] .sf-bv-dot .sf-sr').first().innerText()) === 'Червен', JSON.stringify(dots));
    check('1280: the meta row says «Избрани филтри: 0» and has no «Изчисти» without a selection', (await page.locator('.sf-bctl[open] .sf-bctl-chosen').innerText()) === 'Избрани филтри: 0' && (await page.locator('.sf-bctl[open] .sf-bctl-clear').count()) === 0);
    await shot('cd1c-02-bar-swatches-open-1280');

    // opening another closes this one (native name)
    await page.locator('.sf-bctl-sum', { hasText: 'Материя' }).click();
    check('1280: opening another control closes the first (one open at a time)', (await page.locator('.sf-bctl[open]').count()) === 1);
    const list = await page.$$eval('.sf-bctl[open] .sf-bv-list', (els) => els.map((e) => { const cs = getComputedStyle(e, '::before'); const r = e.getBoundingClientRect(); return { h: Math.round(r.height), w: cs.width, bw: cs.borderTopWidth, br: cs.borderTopLeftRadius, x: Math.round(r.x) }; }));
    const listBox = await style(page, '.sf-bctl[open] .sf-bctl-values', ['maxHeight', 'overflowY']);
    check('1280: the list panel is ONE column of rows ≥ 36px with a 20px checkbox (1px border, 3px radius) and scrolls after 220px', list.length === 9 && list.every((r) => r.h >= 36 && r.w === '20px' && r.bw === '1px' && r.br === '3px' && r.x === list[0].x) && listBox.maxHeight === '220px' && listBox.overflowY === 'auto' && listBox.h <= 221, JSON.stringify({ first: list[0], box: listBox }));
    await shot('cd1c-03-bar-list-open-1280');

    // choose two values: each applies at once (the value link)
    await nav(page, page.locator('.sf-bctl[open] .sf-bv-list', { hasText: 'Памук' }));
    check('1280: a choice applies at once: the canonical URL, no button was needed', qs(page).includes(`filter[${ids.material}]=${ids.m['Памук']}`) && ! qs(page).includes('[]'), page.url());
    await page.locator('.sf-bctl-sum', { hasText: 'Цвят' }).click();
    await nav(page, page.locator('.sf-bctl[open] .sf-bv-dot[title="Червен"]'));
    await page.locator('.sf-bctl-sum', { hasText: 'Размер' }).click();
    await sleep(300);
    check('1280: Цвят and Материя show «Име: избраните» (name weight 500) in their controls', (await page.locator('.sf-bctl-sum', { hasText: 'Цвят' }).innerText()).replace(/\s+/g, ' ').trim() === 'Цвят: Червен' && (await page.locator('.sf-bctl-sum strong.sf-bctl-strong', { hasText: 'Материя' }).count()) === 1 && (await page.locator('.sf-bctl-sum strong', { hasText: 'Цвят' }).evaluate((e) => getComputedStyle(e).fontWeight)) === '500');
    const linksPanel = await page.$$eval('.sf-bctl[open] .sf-bv-link', (els) => els.map((e) => ({ x: Math.round(e.getBoundingClientRect().x), on: e.classList.contains('sf-bv-on') })));
    check('1280: the text-links panel is ONE column', linksPanel.length >= 2 && linksPanel.every((l) => l.x === linksPanel[0].x));
    await shot('cd1c-04-bar-two-selections-1280');
    await page.locator('.sf-bctl-sum', { hasText: 'Цвят' }).click();
    check('1280: with a selection the meta row shows «Избрани филтри: 1» and no control has its own «Изчисти» (CD-1c.1)', (await page.locator('.sf-bctl[open] .sf-bctl-chosen').innerText()) === 'Избрани филтри: 1' && (await page.locator('.sf-bctl-clear, .sf-bctl .sf-bar-clear').count()) === 0);
    const sel = await page.$eval('.sf-bctl[open] .sf-bv-dot.sf-bv-on', (e) => getComputedStyle(e).boxShadow);
    check('1280: the selected circle has a 1px ink ring 3px outside', /0px 0px 0px 3px/.test(sel) && /0px 0px 0px 4px/.test(sel), sel);
    await shot('cd1c-05-bar-clear-visible-1280');
    const countNow = await page.locator('.sf-count').innerText();
    check('1280: «Брой продукти» follows the result', /^Брой продукти: \d+$/.test(countNow) && parseInt(countNow.split(': ')[1], 10) < 60 && parseInt(countNow.split(': ')[1], 10) === (await page.evaluate(() => parseInt(document.querySelector('.sf-pagination-status')?.textContent.match(/\d+ от (\d+)/)?.[1] ?? '1', 10)) >= 0 ? parseInt(countNow.split(': ')[1], 10) : -1), countNow);

    // the sort control in the bar
    await page.locator('.sf-bctl-sort .sf-bctl-sum').click();
    const cur = await page.$eval('.sf-bctl-sort .sf-bv-on', (e) => ({ w: getComputedStyle(e).fontWeight, t: e.textContent.trim() }));
    check('1280: the sort panel lists plain links, the current one in weight 500', cur.t === 'Най-нови' && cur.w === '500', JSON.stringify(cur));
    await nav(page, page.locator('.sf-bctl-sort .sf-bv', { hasText: 'По име' }));
    check('1280: choosing «По име» keeps the filter and sets sort=name_asc; the control now says «По име»', qs(page).includes('sort=name_asc') && qs(page).includes(`filter[${ids.material}]`) && (await page.locator('.sf-bctl-sort .sf-bctl-text').innerText()) === 'По име', qs(page));
    // keyboard focus
    await page.locator('.sf-bctl-sum').nth(1).focus();
    await page.keyboard.press('Tab');
    const focusRing = await page.evaluate(() => { document.querySelector('.sf-bctl-sum').focus(); const cs = getComputedStyle(document.activeElement); return { o: cs.outlineWidth + ' ' + cs.outlineStyle, off: cs.outlineOffset }; });
    check('1280: keyboard focus is a 2px solid ring with a 2px offset', /^2px solid/.test(focusRing.o) && focusRing.off === '2px', JSON.stringify(focusRing));

    // CD-1c.1: the ONE «Изчисти» of the bar: the last cell, on the controls' row, underlined; it clears every filter and keeps the sort; gone with no selection
    const clearBox = await page.locator('.sf-bar-clear').boundingBox();
    const lastCtl = await page.locator('.sf-cbar > .sf-bctl').last().boundingBox();
    const lastIsClear = await page.$eval('.sf-cbar', (e) => e.lastElementChild.classList.contains('sf-bar-clear'));
    check('1280: ONE «Изчисти» is the last cell of the bar, on the row of the controls, after the last control', lastIsClear && (await page.locator('.sf-bar-clear').count()) === 1 && near(clearBox.y, lastCtl.y, 2) && clearBox.x > lastCtl.x, JSON.stringify({ clearBox, lastCtl }));
    // flex-end: the clear's TEXT sits on the bottom of the row, on the same line as the controls' bottom rule (measured on the text, not the box)
    const textRect = (sel) => page.$eval(sel, (el) => { const r = document.createRange(); r.selectNodeContents(el); const b = r.getBoundingClientRect(); return { top: b.top, bottom: b.bottom }; });
    const clearTxt = await textRect('.sf-bar-clear');
    const ruleY = lastCtl.y + lastCtl.height;
    check('1280: the bar «Изчисти» text sits on the bottom line of the row: its text bottom is 0–6px above the controls\' bottom rule', ruleY - clearTxt.bottom >= 0 && ruleY - clearTxt.bottom <= 6, JSON.stringify({ textBottom: clearTxt.bottom, controlRule: ruleY, gap: ruleY - clearTxt.bottom }));
    const clearCss = await page.locator('.sf-bar-clear').evaluate((e) => ({ dec: getComputedStyle(e).textDecorationLine, size: getComputedStyle(e).fontSize, h: e.getBoundingClientRect().height }));
    check('1280: the bar «Изчисти» is 12px, underlined and as high as a control (44px)', clearCss.dec === 'underline' && clearCss.size === '12px' && clearCss.h === 44, JSON.stringify(clearCss));
    await shot('cd1c-05b-bar-clear-after-last-control-1280', null);
    await nav(page, page.locator('.sf-bar-clear'));
    check('1280: «Изчисти» clears every filter and keeps the sort (the canonical URL)', ! qs(page).includes('filter[') && qs(page).includes('sort=name_asc'), page.url());
    check('1280: with no selection the bar has no «Изчисти»', (await page.locator('.sf-bar-clear').count()) === 0);
    await ctx.close();

    // ============================================================ 1000: 4 columns
    ({ ctx, page, shot } = await open(1000, URL, 'cd1c-06-bar-1000'));
    const cols1000 = (await style(page, '.sf-cbar', ['gridTemplateColumns'])).gridTemplateColumns.split(' ').length;
    check('1000: the bar has 4 columns', cols1000 === 4 && await noOverflow(page), String(cols1000));
    await ctx.close();

    // ============================================================ 360 / 320: the drawer
    ({ ctx, page, shot } = await open(360, URL, 'cd1c-07-phone-closed-360'));
    check('360: no bar and no panel; the trigger link «Филтриране и сортиране» is shown; «Брой продукти» beside the title', ! (await page.locator('.sf-cbar').first().isVisible()) && (await page.locator('.sf-ftrigger').isVisible()) && /^Брой продукти: 60$/.test((await page.locator('.sf-cat-head .sf-count').innerText()).trim()) && await noOverflow(page));
    await page.locator('.sf-ftrigger').click();
    await sleep(500);
    const dh = await style(page, '.sf-fdrawer-head', ['borderBottomWidth']);
    const title = await page.locator('.sf-fdrawer-title').innerText();
    const titleBox = await page.locator('.sf-fdrawer-title').boundingBox();
    const panelBox = await page.locator('.sf-fdrawer-panel').boundingBox();
    check('360: level 1: the header is 56px with a bottom rule, the × on the left and the title «Филтри» centred', dh.h === 56 && dh.borderBottomWidth === '1px' && title === 'Филтри' && near(titleBox.x + titleBox.width / 2, panelBox.x + panelBox.width / 2, 3) && (await page.locator('.sf-fdrawer-close').boundingBox()).x < panelBox.x + 60, JSON.stringify({ dh, titleBox, panelBox }));
    const rows = await page.$$eval('.sf-fgroup-summary', (els) => els.map((e) => ({ name: e.querySelector('.sf-fgroup-name').textContent.trim(), cur: e.querySelector('.sf-fgroup-current').textContent.trim(), h: Math.round(e.getBoundingClientRect().height), bb: getComputedStyle(e.parentElement).borderBottomWidth, nameSize: getComputedStyle(e.querySelector('.sf-fgroup-name')).fontSize, w: getComputedStyle(e.querySelector('.sf-fgroup-current')).fontWeight })));
    check('360: level 1: «Брой продукти: N» on top; rows ≥ 56px with a 1px rule, name 15px, the SORT row first, then Цвят, Размер, Материя', (await page.locator('.sf-fcount').innerText()) === 'Брой продукти: 60' && rows.map((r) => r.name).join('|') === 'Подредба|Цвят|Размер|Материя' && rows.every((r) => r.h >= 56 && r.bb === '1px' && r.nameSize === '15px') && rows[0].cur === 'Най-нови' && rows[0].w === '600', JSON.stringify(rows));
    const chevrons = await page.$$eval('.sf-fgroup-summary', (els) => els.map((e) => getComputedStyle(e, '::after').width));
    check('360: every row ends with a thin chevron', chevrons.every((w) => w === '7px'), JSON.stringify(chevrons));
    const foot = await style(page, '.sf-fdrawer-foot', ['borderTopWidth', 'position']);
    const btn = await style(page, '.sf-fbtn-outline', ['backgroundColor', 'borderTopWidth', 'borderTopStyle', 'fontSize', 'fontWeight', 'borderRadius']);
    check('360: the foot has a 1px top rule; «Филтрирай» is an outline button 48px high, 13px weight 500, white fill (no solid black), no count on it; no «Изчисти» without a selection', foot.borderTopWidth === '1px' && btn.h === 48 && btn.backgroundColor === 'rgb(255, 255, 255)' && btn.borderTopWidth === '1px' && btn.borderTopStyle === 'solid' && btn.fontSize === '13px' && btn.fontWeight === '500' && ! /\d/.test(await page.locator('.sf-fbtn-outline').innerText()) && (await page.locator('.sf-fclear').count()) === 0, JSON.stringify({ foot, btn }));
    await shot('cd1c-08-phone-level1-360');

    // level 2: one filter
    await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).click();
    await sleep(400);
    const l2head = await style(page, '.sf-fgroup[open] > .sf-fgroup-summary', ['borderBottomWidth', 'justifyContent']);
    const back = await page.evaluate(() => { const cs = getComputedStyle(document.querySelector('.sf-fgroup[open] > .sf-fgroup-summary'), '::before'); return { c: cs.content, left: cs.left }; });
    check('360: level 2: the filter\'s screen is laid over the drawer: a 56px header with a back arrow and the name centred, «Избрани филтри: 0», values ≥ 48px, the foot still there', l2head.h === 56 && l2head.justifyContent === 'center' && back.c !== 'none' && (await page.locator('.sf-fgroup[open] .sf-fgroup-meta').innerText()) === 'Избрани филтри: 0' && (await page.locator('.sf-fgroup[open] .sf-fv-link').first().boundingBox()).height >= 48 && (await page.locator('.sf-fdrawer-foot .sf-fbtn-outline').isVisible()), JSON.stringify({ l2head, back }));
    await shot('cd1c-09-phone-level2-360');
    // choose two sizes, go back: the row shows them, the page did not change until «Филтрирай»
    await page.locator('.sf-fgroup[open] .sf-fv-link', { hasText: /^\s*S\s*$/ }).click();
    await page.locator('.sf-fgroup[open] .sf-fv-link', { hasText: /^\s*M\s*$/ }).click();
    check('360: a choice in the drawer does not apply by itself', page.url().endsWith(URL));
    await page.locator('.sf-fgroup[open] > .sf-fgroup-summary').click();
    await sleep(300);
    check('360: the back arrow returns to level 1', (await page.locator('.sf-fgroup[open]').count()) === 0 && (await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).isVisible()));
    await nav(page, page.locator('.sf-fbtn-outline'));
    check('360: «Филтрирай» applies the multi-choice (the canonical URL)', qs(page).includes(`filter[${ids.size}]=${ids.s['S']},${ids.s['M']}`) && ! qs(page).includes('[]'), qs(page));
    await page.locator('.sf-ftrigger').click();
    await sleep(500);
    const row = await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).evaluate((e) => ({ cur: e.querySelector('.sf-fgroup-current').textContent.trim(), w: getComputedStyle(e.querySelector('.sf-fgroup-current')).fontWeight }));
    check('360: with a selection: the row shows the chosen values in weight 600, «Изчисти» (underlined text link) is on the left of the foot and «Филтрирай» fills the rest', row.cur === 'S, M' && row.w === '600' && (await page.locator('.sf-fdrawer-foot .sf-fclear').count()) === 1 && (await page.locator('.sf-fdrawer-foot .sf-fclear').boundingBox()).x < (await page.locator('.sf-fbtn-outline').boundingBox()).x, JSON.stringify(row));
    await page.locator('.sf-fgroup-summary', { hasText: 'Размер' }).click();
    await sleep(300);
    check('360: level 2 with a selection says «Избрани филтри: 2»', (await page.locator('.sf-fgroup[open] .sf-fgroup-meta').innerText()) === 'Избрани филтри: 2');
    const labelColours = await page.$$eval('.sf-fgroup[open] .sf-fv-link', (els) => els.map((e) => ({ t: e.textContent.trim(), c: getComputedStyle(e.querySelector('.sf-fv-text')).color, w: getComputedStyle(e.querySelector('.sf-fv-text')).fontWeight })));
    check('360: in a text-links screen the chosen ones are ink, weight 500 and underlined, the others muted grey (nothing is blue)', labelColours.every((l) => l.c === 'rgb(26, 26, 26)' || l.c === 'rgb(107, 107, 107)'), JSON.stringify(labelColours));
    const l2top = await style(page, '.sf-fgroup[open]', []);
    check('360: the open screen covers the whole drawer from the top (the level 1 header is replaced)', l2top.y === 0, JSON.stringify(l2top));
    await shot('cd1c-10-phone-selection-360');
    // the colour level: circles; the material level: a list with checkboxes
    await page.locator('.sf-fgroup[open] > .sf-fgroup-summary').click();
    await page.locator('.sf-fgroup-summary', { hasText: 'Цвят' }).click();
    await sleep(300);
    const dotsPhone = await page.$$eval('.sf-fgroup[open] .sf-fv-dot', (els) => els.map((e) => Math.round(e.getBoundingClientRect().width)));
    check('360: the colour screen shows the circles (28px)', dotsPhone.length >= 2 && dotsPhone.every((w) => w === 28), JSON.stringify(dotsPhone));
    await page.locator('.sf-fgroup[open] > .sf-fgroup-summary').click();
    await page.locator('.sf-fgroup-summary', { hasText: 'Материя' }).click();
    await sleep(300);
    const mat = await page.$$eval('.sf-fgroup[open] .sf-fv-list', (els) => els.map((e) => ({ h: Math.round(e.getBoundingClientRect().height), box: getComputedStyle(e.querySelector('.sf-fv-mark')).width })));
    check('360: the material screen shows rows ≥ 44px with the 20px checkbox look and nothing overflows', mat.length >= 2 && mat.every((r) => r.h >= 44 && r.box === '20px') && await noOverflow(page), JSON.stringify(mat.slice(0, 2)));
    await ctx.close();

    ({ ctx, page, shot } = await open(320, URL + `?filter[${ids.size}]=${ids.s['S']}`, 'cd1c-11-phone-320'));
    await page.locator('.sf-ftrigger').click();
    await sleep(500);
    check('320: the page and the open drawer have no horizontal scroll', await noOverflow(page));
    await page.locator('.sf-fgroup-summary', { hasText: 'Материя' }).click();
    await sleep(300);
    check('320: a filter screen has no horizontal scroll either', await noOverflow(page) && (await page.$eval('.sf-fgroup[open]', (e) => e.scrollWidth <= e.clientWidth + 1)));
    await shot('cd1c-12-phone-level2-320');
    await ctx.close();

    // ============================================================ 1280: the side panel with the script: no «Филтрирай», no empty foot, «Изчисти» only with a selection
    php(LAYOUT('sidebar'));
    ({ ctx, page, shot } = await open(1280, URL, 'cd1c-13-sidebar-empty-1280'));
    check('1280 side panel: the panel is shown beside the list', await page.locator('.sf-cat-layout-sidebar .sf-fdrawer').first().isVisible());
    const btnShown = await page.locator('.sf-cat-layout-sidebar .sf-fdrawer-foot .sf-fbtn-outline').first().isVisible();
    const footShown = await page.locator('.sf-cat-layout-sidebar .sf-fdrawer-foot').first().isVisible();
    check('1280 side panel, script on, nothing selected: no «Филтрирай», no «Изчисти», no empty foot under the list', ! btnShown && ! footShown && (await page.locator('.sf-cat-layout-sidebar .sf-fclear').count()) === 0, JSON.stringify({ btnShown, footShown }));
    await nav(page, page.locator('.sf-cat-layout-sidebar .sf-fv-list', { hasText: 'Памук' }));
    check('1280 side panel: a choice still applies at once, with no button', qs(page).includes(`filter[${ids.material}]=${ids.m['Памук']}`), page.url());
    check('1280 side panel, with a selection: «Изчисти» is visible and the foot shows, «Филтрирай» stays hidden', (await page.locator('.sf-cat-layout-sidebar .sf-fclear').first().isVisible()) && (await page.locator('.sf-cat-layout-sidebar .sf-fdrawer-foot').first().isVisible()) && ! (await page.locator('.sf-cat-layout-sidebar .sf-fbtn-outline').first().isVisible()));
    await shot('cd1c-14-sidebar-selected-1280');
    await ctx.close();
    php(LAYOUT('full'));
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('everything created is gone: the settings and the catalog tables are as they were (checksums / counts)', before && JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
        } catch (e) {
            check('the restore finished', false, String(e).slice(0, 400));
        }
    }
}

check('no page errors', consoleErrors.length === 0, consoleErrors.slice(0, 5).join(' | '));
await browser.close();

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
