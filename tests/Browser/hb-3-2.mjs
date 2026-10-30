// A REAL-BROWSER run of HB-3.2: a block with centre / right alignment sits in the middle / at the right of its place. NOT part of `php artisan test`: run by hand against a dev site whose data may be changed.
// It creates four throw-away blocks (a narrow centred one with a heading and two paragraphs; a narrow right-aligned one; a narrow left-aligned one with a background; a full-width centred one with padding and a
// background), puts them into the right column, BACKS UP the `site_settings` table and the `appearance/` files first, and at the end removes the blocks it made and restores both (a checksum of `site_settings`).
// The mega menu and the footer are NOT measured here (the dev shop has no block placed there; see the report). Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-3-2.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');
const TITLES = ['HB32 C', 'HB32 R', 'HB32 L', 'HB32 F'];

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-3-2.mjs');
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
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage; use App\Content\HtmlBlocks\HtmlBlockModel; use App\Storefront\ProductSummary\ProductSummaryCompiler; use App\Storefront\ProductSummary\ProductSummaryLayout; use App\Settings\Contracts\SiteSettingsRepository;
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
$files = Storage::disk('public')->allFiles('appearance');
file_put_contents(sys_get_temp_dir().'/hb32_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('html_blocks')->whereIn('title', ['HB32 C', 'HB32 R', 'HB32 L', 'HB32 F'])->delete();
$c = HtmlBlockModel::create(['title' => 'HB32 C', 'body' => '<h2>Промокод Есен -10%</h2><p>Важи до края на месеца.</p><p>Един код на поръчка.</p>', 'style' => ['align' => 'center']]);
$r = HtmlBlockModel::create(['title' => 'HB32 R', 'body' => '<p>Вдясно, по съдържание.</p>', 'style' => ['align' => 'right', 'bg' => '#e8f0fe']]);
$l = HtmlBlockModel::create(['title' => 'HB32 L', 'body' => '<p>Вляво, по съдържание.</p>', 'style' => ['align' => 'left', 'bg' => '#fde8e8']]);
$f = HtmlBlockModel::create(['title' => 'HB32 F', 'body' => '<h2>Цялата ширина</h2><p>Центриран текст в кутия.</p>', 'style' => ['align' => 'center', 'width_mode' => 'full', 'pad_v' => 16, 'pad_h' => 20, 'bg' => '#f5f0e8']]);
$layout = ProductSummaryLayout::default();
$layout['components'] = [['type' => 'title', 'settings' => []], ['type' => 'html_block', 'settings' => ['html_block' => $c->id]], ['type' => 'html_block', 'settings' => ['html_block' => $r->id]], ['type' => 'html_block', 'settings' => ['html_block' => $l->id]], ['type' => 'html_block', 'settings' => ['html_block' => $f->id]], ['type' => 'purchase', 'settings' => []]];
app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
$slug = DB::table('catalog_products')->where('status', 'active')->where('catalog_visibility', 'visible')->orderBy('id')->value('slug');
echo json_encode(['sum' => md5(json_encode($rows)), 'slug' => $slug]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb32_backup.json'), true);
DB::table('html_blocks')->whereIn('title', ['HB32 C', 'HB32 R', 'HB32 L', 'HB32 F'])->delete();
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'blocks' => DB::table('html_blocks')->whereIn('title', ['HB32 C', 'HB32 R', 'HB32 L', 'HB32 F'])->count()]);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const diag = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 600) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let backedUp = false;

// each styled box in the right column: its offsets from the column's CONTENT edges (inside the column's own padding), and the text's gaps to the box's content edges
const measure = (page) => page.evaluate(() => {
    const column = document.querySelector('.sf-summary');
    const cs0 = getComputedStyle(column);
    const colL = column.getBoundingClientRect().left + parseFloat(cs0.paddingLeft);
    const colR = column.getBoundingClientRect().right - parseFloat(cs0.paddingRight);

    return [...document.querySelectorAll('.sf-summary-html')].filter((x) => x.querySelector('.sf-hb')).map((box) => {
        const hb = box.querySelector('.sf-hb'); const hr = hb.getBoundingClientRect(); const cs = getComputedStyle(hb);
        const padL = parseFloat(cs.paddingLeft), padR = parseFloat(cs.paddingRight);
        const lines = [...hb.children].map((el) => {
            const r = document.createRange(); r.selectNodeContents(el); const rects = [...r.getClientRects()];

            return { tag: el.tagName.toLowerCase(), gapL: Math.round(Math.min(...rects.map((x) => x.left)) - (hr.left + padL)), gapR: Math.round((hr.right - padR) - Math.max(...rects.map((x) => x.right))) };
        });
        const first = (hb.textContent || '').trim().slice(0, 14);

        return { first, align: cs.textAlign, width: Math.round(hr.width), colWidth: Math.round(colR - colL), leftOffset: Math.round(hr.left - colL), rightOffset: Math.round(colR - hr.right), lines };
    });
});

const byText = (blocks, text) => blocks.find((b) => b.first.startsWith(text));

try {
    const setup = JSON.parse(php(SETUP));
    backedUp = true;
    console.error('backup + blocks: ' + JSON.stringify(setup));
    const url = BASE + '/product/' + setup.slug;

    const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => diag.push('PAGEERROR ' + String(e.message || e).slice(0, 200)));
    await page.goto(url, { waitUntil: 'networkidle' });
    await sleep(400);
    const desk = await measure(page);
    console.error('desktop: ' + JSON.stringify(desk.map((b) => [b.first, b.align, b.width, b.colWidth, b.leftOffset, b.rightOffset])));

    const C = byText(desk, 'Промокод'), R = byText(desk, 'Вдясно'), L = byText(desk, 'Вляво'), F = byText(desk, 'Цялата');
    check('the four blocks are in the right column', desk.length === 4 && C && R && L && F, String(desk.length));

    if (C) {
        check('desktop: the centred narrow box is in the middle (left and right offsets within 1px)', Math.abs(C.leftOffset - C.rightOffset) <= 1, `${C.leftOffset}/${C.rightOffset}`);
        check('desktop: the centred box is narrower than the column', C.width < C.colWidth - 20, `${C.width} of ${C.colWidth}`);
        check('desktop: every line of the centred box is centred (equal gaps left and right within 1px)', C.lines.every((l) => Math.abs(l.gapL - l.gapR) <= 1), JSON.stringify(C.lines));
    }
    if (R) {
        check('desktop: the right-aligned narrow box sits at the right edge (right offset 0 within 1px)', R.rightOffset <= 1, String(R.rightOffset));
        check('desktop: the right-aligned box is narrower than the column', R.width < R.colWidth - 20, `${R.width} of ${R.colWidth}`);
    }
    if (L) {
        check('desktop: the left-aligned narrow box stays at the left edge (left offset 0 within 1px)', L.leftOffset <= 1, String(L.leftOffset));
    }
    if (F) {
        check('desktop: the full-width box fills the column', Math.abs(F.width - F.colWidth) <= 1, `${F.width} of ${F.colWidth}`);
        check('desktop: the text of the full-width box is centred (equal gaps within 1px)', F.lines.every((l) => Math.abs(l.gapL - l.gapR) <= 1), JSON.stringify(F.lines));
    }

    if (OUT) {
        await page.locator('.sf-summary').screenshot({ path: join(OUT, 'hb32-desktop-column.png') }); shots.push(join(OUT, 'hb32-desktop-column.png'));
    }

    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
    const pp = await phone.newPage();
    pp.on('pageerror', (e) => diag.push('PHONE PAGEERROR ' + String(e.message || e).slice(0, 200)));
    await pp.goto(url, { waitUntil: 'networkidle' });
    await sleep(400);
    const ph = await measure(pp);
    const PC = byText(ph, 'Промокод'), PR = byText(ph, 'Вдясно'), PL = byText(ph, 'Вляво');
    console.error('phone: ' + JSON.stringify(ph.map((b) => [b.first, b.width, b.colWidth, b.leftOffset, b.rightOffset])));
    check('phone: the centred box is in the middle (offsets within 1px)', PC && Math.abs(PC.leftOffset - PC.rightOffset) <= 1, PC ? `${PC.leftOffset}/${PC.rightOffset}` : 'missing');
    check('phone: the right-aligned box sits at the right edge', PR && PR.rightOffset <= 1, PR ? String(PR.rightOffset) : 'missing');
    check('phone: the left-aligned box stays at the left edge', PL && PL.leftOffset <= 1, PL ? String(PL.leftOffset) : 'missing');
    check('phone: nothing scrolls sideways', await pp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    if (OUT) {
        await pp.locator('.sf-summary').screenshot({ path: join(OUT, 'hb32-phone-column.png') }); shots.push(join(OUT, 'hb32-phone-column.png'));
    }
    await phone.close();
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(RESTORE));
        check('restored: site_settings is byte-for-byte what it was', restored.sum === restored.was, JSON.stringify(restored));
        check('restored: the four throw-away blocks are gone', restored.blocks === 0, JSON.stringify(restored));
    }
}

check('no page errors', diag.length === 0, diag.slice(0, 5).join(' | '));
const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
