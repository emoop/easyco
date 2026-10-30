// A REAL-BROWSER run of HB-3.1: centred text in an HTML block of the right column after the paragraph spacing of HB-3. NOT part of `php artisan test`: run by hand against a dev site whose data may be
// changed. It creates three throw-away blocks (a centred heading with two paragraphs; a centred single paragraph; a full-width centred box with padding and a background), puts them into the right column,
// BACKS UP the `site_settings` table and the `appearance/` files first, and at the end removes the blocks it made and restores both (a checksum of `site_settings`). Prints a JSON report; exit code 1 when a check failed.
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder for screenshots> node tests/Browser/hb-3-1.mjs
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');
const TITLES = ['HB31 A', 'HB31 B', 'HB31 C'];

if (! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: PLAYWRIGHT_DIR=... node hb-3-1.mjs');
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
file_put_contents(sys_get_temp_dir().'/hb31_backup.json', json_encode(['rows' => $rows, 'files' => $files, 'contents' => array_combine($files, array_map(fn ($f) => base64_encode(Storage::disk('public')->get($f)), $files)), 'sum' => md5(json_encode($rows))]));
DB::table('html_blocks')->whereIn('title', ['HB31 A', 'HB31 B', 'HB31 C'])->delete();
$a = HtmlBlockModel::create(['title' => 'HB31 A', 'body' => '<h2>Промокод Есен -10%</h2><p>Важи до края на месеца.</p><p>Един код на поръчка.</p>', 'style' => ['align' => 'center']]);
$b = HtmlBlockModel::create(['title' => 'HB31 B', 'body' => '<p>Само един абзац.</p>', 'style' => ['align' => 'center']]);
$c = HtmlBlockModel::create(['title' => 'HB31 C', 'body' => '<h2>Заглавие в кутия</h2><p>Първи абзац в кутията.</p><p>Втори абзац.</p>', 'style' => ['align' => 'center', 'width_mode' => 'full', 'pad_v' => 16, 'pad_h' => 20, 'bg' => '#f5f0e8']]);
$layout = ProductSummaryLayout::default();
$layout['components'] = [['type' => 'title', 'settings' => []], ['type' => 'html_block', 'settings' => ['html_block' => $a->id]], ['type' => 'html_block', 'settings' => ['html_block' => $b->id]], ['type' => 'html_block', 'settings' => ['html_block' => $c->id]], ['type' => 'purchase', 'settings' => []]];
app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
$slug = DB::table('catalog_products')->where('status', 'active')->where('catalog_visibility', 'visible')->orderBy('id')->value('slug');
echo json_encode(['sum' => md5(json_encode($rows)), 'slug' => $slug, 'blocks' => [$a->id, $b->id, $c->id]]);`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$bak = json_decode(file_get_contents(sys_get_temp_dir().'/hb31_backup.json'), true);
DB::table('html_blocks')->whereIn('title', ['HB31 A', 'HB31 B', 'HB31 C'])->delete();
DB::table('site_settings')->delete();
foreach ($bak['rows'] as $row) { DB::table('site_settings')->insert($row); }
foreach (Storage::disk('public')->allFiles('appearance') as $f) { if (! in_array($f, $bak['files'], true)) { Storage::disk('public')->delete($f); } }
foreach ($bak['contents'] as $f => $content) { Storage::disk('public')->put($f, base64_decode($content)); }
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
$rows = DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
echo json_encode(['sum' => md5(json_encode($rows)), 'was' => $bak['sum'], 'blocks' => DB::table('html_blocks')->whereIn('title', ['HB31 A', 'HB31 B', 'HB31 C'])->count()]);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = [];
const diag = [];
const shots = [];
const check = (name, ok, detail = '') => { steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 600) }); if (! ok) console.error('FAIL ' + name + ' ' + detail); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let backedUp = false;

// the measurements of each block in the right column: the text's gaps to the content box (left/right/top/bottom), the margins and the alignment
const measure = (page) => page.evaluate(() => [...document.querySelectorAll('.sf-summary-html')].filter((x) => x.querySelector('.sf-hb')).map((box) => {
    const hb = box.querySelector('.sf-hb'); const hr = hb.getBoundingClientRect(); const cs = getComputedStyle(hb);
    const padL = parseFloat(cs.paddingLeft), padR = parseFloat(cs.paddingRight), padT = parseFloat(cs.paddingTop), padB = parseFloat(cs.paddingBottom);
    const items = [...hb.children].map((el) => {
        const r = document.createRange(); r.selectNodeContents(el); const rects = [...r.getClientRects()];
        const s = getComputedStyle(el);

        return { tag: el.tagName.toLowerCase(), align: s.textAlign, mt: s.marginTop, mb: s.marginBottom, top: el.getBoundingClientRect().top, bottom: el.getBoundingClientRect().bottom,
            gapL: Math.round(Math.min(...rects.map((x) => x.left)) - (hr.left + padL)), gapR: Math.round((hr.right - padR) - Math.max(...rects.map((x) => x.right))) };
    });

    // the text's vertical gaps inside the box's padding: above the first line and below the last one (line-height leading is part of each line)
    const firstTop = [...hb.children][0].getBoundingClientRect().top;
    const lastBottom = [...hb.children].at(-1).getBoundingClientRect().bottom;
    const topGap = Math.round(firstTop - (hr.top + padT)), bottomGap = Math.round((hr.bottom - padB) - lastBottom);

    // where the box itself sits in the column: a «fit-content» box is only as wide as its widest line and stays at the column's left edge (measured, not asserted: see the report)
    const column = document.querySelector('.sf-summary').getBoundingClientRect();
    const boxOffsetLeft = Math.round(hr.left - column.left), boxOffsetRight = Math.round(column.right - hr.right);

    return { hbAlign: cs.textAlign, pad: [padT, padR, padB, padL], items, topGap, bottomGap, boxOffsetLeft, boxOffsetRight, lastIsP: hb.lastElementChild?.tagName === 'P' };
}));

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
    const blocks = await measure(page);
    console.error('desktop: ' + JSON.stringify(blocks));

    check('three styled blocks are in the right column', blocks.length === 3, String(blocks.length));
    // MEASURED, not a check: the box offsets from the column's edges (A and B are narrow boxes at the left, C fills the column)
    console.error('box offsets (left, right) from the column edges: ' + blocks.map((blk) => `${blk.boxOffsetLeft}/${blk.boxOffsetRight}`).join('  ') + ' px');
    const [A, B, C] = blocks;

    // centred: the text of every line sits at the same distance from both sides of its box
    for (const [name, block] of [['A (heading + two paragraphs)', A], ['B (one paragraph)', B], ['C (full width, padded)', C]]) {
        check(`${name}: the block is centred (text-align centre)`, block.hbAlign === 'center', block.hbAlign);
        const sym = block.items.every((i) => Math.abs(i.gapL - i.gapR) <= 1);
        check(`${name}: every line is centred: equal gaps left and right within 1px`, sym, JSON.stringify(block.items.map((i) => [i.tag, i.gapL, i.gapR])));
    }

    // the gap: 8px between two paragraphs (margin-top 0.5em of 16px), none above the first, none below the last
    const gapOf = (block) => block.items[block.items.length - 1].top - block.items[block.items.length - 2].bottom;
    check('the two paragraphs of A have an 8px gap between them', Math.abs(gapOf(A) - 8) <= 1, String(gapOf(A)));
    check('the first paragraph of A has no top margin and the last one no bottom margin', A.items[1].mt === '0px' && A.items[2].mb === '0px', JSON.stringify(A.items.map((i) => [i.tag, i.mt, i.mb])));
    check('the single paragraph of B has no margin at all', B.items[0].mt === '0px' && B.items[0].mb === '0px', JSON.stringify(B.items.map((i) => [i.tag, i.mt, i.mb])));
    check('the padded box C has its padding on both sides (16px top and bottom)', C.pad[0] === 16 && C.pad[2] === 16, JSON.stringify(C.pad));
    // the box C has no fixed height: its text stands between the two paddings. The gaps differ only by the line-height leading of the first and last line (a few px, measured here), not by a margin
    check(`the box C: the text sits between its paddings, top gap ${C.topGap}px and bottom gap ${C.bottomGap}px (no margin below the text)`, C.items.at(-1).mb === '0px' && Math.abs(C.topGap - C.bottomGap) <= 4, JSON.stringify({ top: C.topGap, bottom: C.bottomGap, lastMb: C.items.at(-1).mb }));

    if (OUT) {
        const block = (idx) => page.locator('.sf-summary-html').filter({ has: page.locator('.sf-hb') }).nth(idx);
        await block(0).screenshot({ path: join(OUT, 'hb31-desktop-A.png') }); shots.push(join(OUT, 'hb31-desktop-A.png'));
        await block(2).screenshot({ path: join(OUT, 'hb31-desktop-C.png') }); shots.push(join(OUT, 'hb31-desktop-C.png'));
        await page.locator('.sf-summary').screenshot({ path: join(OUT, 'hb31-desktop-column.png') }); shots.push(join(OUT, 'hb31-desktop-column.png'));
    }

    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
    const pp = await phone.newPage();
    pp.on('pageerror', (e) => diag.push('PHONE PAGEERROR ' + String(e.message || e).slice(0, 200)));
    await pp.goto(url, { waitUntil: 'networkidle' });
    await sleep(400);
    const pb = await measure(pp);
    check('phone: the three blocks are centred the same way (every line, equal gaps within 1px)', pb.length === 3 && pb.every((blk) => blk.items.every((i) => Math.abs(i.gapL - i.gapR) <= 1)), JSON.stringify(pb.map((blk) => blk.items.map((i) => [i.gapL, i.gapR]))));
    check('phone: nothing scrolls sideways', await pp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    if (OUT) {
        await pp.locator('.sf-summary').screenshot({ path: join(OUT, 'hb31-phone-column.png') }); shots.push(join(OUT, 'hb31-phone-column.png'));
    }
    await phone.close();
    await ctx.close();
} catch (e) {
    check('the run finished without an exception', false, String(e.stack || e).replace(/\s+/g, ' ').slice(0, 700));
} finally {
    if (backedUp) {
        const restored = JSON.parse(php(RESTORE));
        check('restored: site_settings is byte-for-byte what it was', restored.sum === restored.was, JSON.stringify(restored));
        check('restored: the three throw-away blocks are gone', restored.blocks === 0, JSON.stringify(restored));
    }
}

check('no page errors', diag.length === 0, diag.slice(0, 5).join(' | '));
const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ total: steps.length, failed: failed.length, screenshots: shots, steps }, null, 1));
process.exit(failed.length ? 1 : 0);
