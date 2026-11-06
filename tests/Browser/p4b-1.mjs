// A REAL-BROWSER check of P4b.1: the product page has no description block; the source «Описание на продукта» of a section (accordion + tabs); a tabs slot prints a heading only when one was typed;
// the right column never scrolls inside itself (it is pinned by its top, or by its bottom edge when it is taller than the screen, and the page scroll reveals the rest).
// NOT part of `php artisan test`: run by hand against the dev site. It READS one existing product (the one with the most pictures and a description) and changes only site_settings rows (sections, right column, tabs slot):
// before the run every site_settings row is backed up, at the end they are put back BY ID and the checksums are compared. A watchdog puts the data back after 8 minutes. Nothing else is created.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] [LINES=12] OUT=<folder outside the project> node tests/Browser/p4b-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const LINES = Number(process.env.LINES ?? 12);
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p4b-1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['settings' => md5(json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())), 'products' => md5(json_encode(DB::table('catalog_products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()))]);`;
const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p4b1_backup.json', json_encode(DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo 'ok';`;
const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/p4b1_backup.json'), true);
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
app(App\Storefront\Header\AppearanceRecompiler::class)->run(app(App\Settings\Contracts\SiteSettingsRepository::class));
$put();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const lines = (n, word) => Array.from({ length: n }, (_, i) => `<p>${word} ред ${i + 1}</p>`).join('');
const APPLY = (heading, n = LINES) => String.raw`
use App\Storefront\Sections\SectionSet; use App\Storefront\Sections\SectionStore; use App\Storefront\Below\BelowSlots; use App\Storefront\Below\BelowStore;
use App\Storefront\ProductSummary\ProductSummaryCompiler; use App\Storefront\ProductSummary\ProductSummaryLayout;
$k = ['aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-000000000004'];
[$c, $e] = SectionSet::validate(['sections' => [
  ['key' => $k[0], 'title' => 'Описание', 'source' => 'description', 'text' => ''],
  ['key' => $k[1], 'title' => 'Доставка', 'source' => 'global', 'text' => '${lines(n, 'Доставка')}'],
  ['key' => $k[2], 'title' => 'Състав', 'source' => 'global', 'text' => '${lines(n, 'Състав')}'],
  ['key' => $k[3], 'title' => 'Индивидуални размери', 'source' => 'product', 'text' => ''],
]]);
SectionStore::publish($c);
$layout = ProductSummaryLayout::default(); $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => false]];
app(ProductSummaryCompiler::class)->publish($layout, app(App\Settings\Contracts\SiteSettingsRepository::class));
[$t, $e2] = BelowSlots::validate(['slots' => [['type' => 'tabs', 'heading' => '${heading}', 'sections' => [$k[0], $k[1], $k[3]]]]]);
BelowStore::publish($t);
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo json_encode([$e, $e2]);`;

const PRODUCT = String.raw`
use Illuminate\Support\Facades\DB;
echo DB::table('catalog_products')->where('status', 'active')->whereRaw("trim(coalesce(description, '')) <> ''")->orderByDesc(DB::raw('(select count(*) from catalog_product_media m where m.product_id = catalog_products.id)'))->value('slug');`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
const steps = []; const shots = []; const report = {}; const consoleErrors = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let before = null; let watchdog = null; let created = false;
try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the data was put back'); process.exit(3); }, 8 * 60 * 1000);
    const slug = php(PRODUCT);
    const url = `${BASE}/product/${encodeURI(slug)}`;
    report.product = slug;
    check('the sections, the accordion and the tabs slot were published without an error', php(APPLY('')) === '[[],[]]');

    const column = async (page) => page.evaluate(() => {
        const c = document.querySelector('.sf-summary'); const s = getComputedStyle(c); const main = document.querySelector('.sf-product-main').getBoundingClientRect(); const r = c.getBoundingClientRect();
        return { sy: Math.round(scrollY), vh: innerHeight, top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height), mainBottom: Math.round(main.bottom), mainTop: Math.round(main.top + scrollY), gallery: Math.round(document.querySelector('.sf-gallery').getBoundingClientRect().height),
            overflowY: s.overflowY, maxHeight: s.maxHeight, position: s.position, scrollH: c.scrollHeight, clientH: c.clientHeight, prop: c.style.getPropertyValue('--sf-summary-top') };
    });

    for (const [width, height, lns] of [[1440, 900, 6], [1024, 640, 4]]) {
        php(APPLY('', lns));
        const ctx = await browser.newContext({ viewport: { width, height }, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text().slice(0, 200)));
        page.on('response', (r) => r.status() >= 500 && consoleErrors.push(`${r.status()} ${r.url()}`));
        await page.goto(url, { waitUntil: 'networkidle' });
        const tag = `${width}`;

        check(`${tag}: no description block under the gallery`, await page.locator('.sf-description').count() === 0);
        const titles = await page.locator('.sf-psec-title').allInnerTexts();
        check(`${tag}: the accordion has «Описание», «Доставка», «Състав» (the product-text section without a text is hidden)`, titles.join('|') === 'Описание|Доставка|Състав', titles.join('|'));
        const tabs = await page.locator('.sf-ptab-label').allInnerTexts();
        check(`${tag}: the tabs are «Описание», «Доставка»`, tabs.join('|') === 'Описание|Доставка', tabs.join('|'));
        check(`${tag}: no slot heading with lines without a typed heading`, await page.locator('.sf-below .sf-hp-heading').count() === 0);
        const descText = await page.locator('.sf-ptab-panel').first().innerText();
        check(`${tag}: the first tab shows the product's description`, descText.trim().length > 3, descText.slice(0, 60));

        const closed = await column(page);
        check(`${tag}: the column has no scrollbar (overflow visible, scrollHeight == clientHeight, no max-height)`, closed.overflowY === 'visible' && closed.scrollH === closed.clientH && closed.maxHeight === 'none', JSON.stringify(closed));
        check(`${tag}: the column is sticky and the script set its top`, closed.position === 'sticky' && closed.prop.startsWith('min('), closed.position + ' ' + closed.prop);
        check(`${tag}: no style attribute in the HTML of the column`, ! (await (await ctx.request.get(url)).text()).includes('<section class="sf-summary" style='));

        const n = await page.locator('.sf-psec-title').count();
        const y0 = await page.evaluate(() => scrollY);
        for (let i = 0; i < n; i++) { await page.locator('.sf-psec-title').nth(i).evaluate((el) => el.click()); }
        await sleep(400);
        const open = await column(page);
        report[`open-${tag}`] = open;
        check(`${tag}: opening the sections does not move the page`, open.sy === y0, `${y0} -> ${open.sy}`);
        check(`${tag}: the opened column still has no inner scroll`, open.overflowY === 'visible' && open.scrollH === open.clientH, JSON.stringify(open));

        if (open.h > open.vh - 20 && open.gallery > open.h + 30) {
            const seen = [];
            for (let y = 0; y <= open.mainTop + open.gallery; y += 25) {
                await page.evaluate((yy) => scrollTo(0, yy), y); await sleep(60);
                const m = await column(page); seen.push([y, m.top, m.bottom]);
            }
            const pinned = seen.filter(([, , b]) => Math.abs(b - (open.vh - 16)) <= 2);
            check(`${tag}: the tall column is pinned by its bottom edge while the gallery is on screen (the page scroll reveals the rest)`, pinned.length >= 1, JSON.stringify(seen));
            const last = seen[seen.length - 1];
            check(`${tag}: at the end of the block the column is released with it`, last[2] <= open.vh + 2, JSON.stringify(last));
        } else {
            check(`${tag}: (scenario) the column is taller than the screen and shorter than the gallery`, false, `column ${open.h}, screen ${open.vh}, gallery ${open.gallery}; try LINES=…`);
        }
        await page.evaluate(() => scrollTo(0, 0));
        await page.screenshot({ path: join(OUT, `p4b1-open-${tag}.png`) }); shots.push(`p4b1-open-${tag}.png`);

        for (let i = 0; i < n; i++) { await page.locator('.sf-psec-title').nth(i).evaluate((el) => el.click()); }
        await sleep(300);
        await page.evaluate(() => scrollTo(0, 300)); await sleep(100);
        const short = await column(page);
        check(`${tag}: a column that fits is pinned by its top (below the header)`, short.top >= 0 && short.top <= 140 && short.h < short.vh, JSON.stringify(short));

        await page.evaluate(() => document.querySelector('.sf-summary').style.setProperty('--sf-summary-position', 'static'));
        const off = await column(page);
        check(`${tag}: sticky off = plain flow, no inner scroll`, off.position === 'static' && off.overflowY === 'visible' && off.scrollH === off.clientH, JSON.stringify(off));
        await ctx.close();
    }

    php(APPLY('Моят заглавие'));
    {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        await page.goto(url, { waitUntil: 'networkidle' });
        const headings = await page.locator('.sf-below .sf-hp-heading').allInnerTexts();
        check('a typed heading is printed once above the tab row', headings.length === 1 && headings[0].includes('Моят заглавие'), JSON.stringify(headings));
        const order = await page.evaluate(() => { const h = document.querySelector('.sf-below .sf-hp-heading'); const t = document.querySelector('.sf-ptabs'); return !! (h.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING); });
        check('the heading is above the tab row', order);
        await page.locator('.sf-ptab-label').nth(1).scrollIntoViewIfNeeded();
        await page.screenshot({ path: join(OUT, 'p4b1-heading-1440.png') }); shots.push('p4b1-heading-1440.png');
        await ctx.close();
    }
    check('no page error and no 5xx during the run', consoleErrors.length === 0, JSON.stringify(consoleErrors.slice(0, 5)));
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            check('site_settings: back EXACTLY as before the run', after.settings === before.settings, `${before.settings} vs ${after.settings}`);
            check('products: untouched', after.products === before.products);
        } catch (e) { check('the data was put back', false, String(e).slice(0, 600)); }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
