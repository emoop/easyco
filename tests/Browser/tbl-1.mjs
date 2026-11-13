// A REAL-BROWSER check of TBL-1: tables in the HTML-block editor (size tables).
//   ADMIN (1440): «HTML блокове» → «Нов блок»: the toolbar's table buttons make a 4 × 6 size table, the cells are typed, the block is saved; the stored body has the table and no style / class / width.
//   STOREFRONT: the block is put into a product section (source «HTML блокове», shown as an accordion) of a throw-away product. At 1440 the table fits its box (no scroll, no shadow); at 390 the page does not scroll
//   sideways, the TABLE scrolls inside its box, the first column keeps its place while the rest scrolls (its left edge is measured before / after), the soft right-edge shadow is visible before scrolling and gone at
//   the end (the edge's brightness is measured in screenshots). The LK-1 look is read from computed styles.
// NOT part of `php artisan test`: run by hand against the dev site (the stylesheet must be built: `npm run build`). BEFORE the run every checksum, the staff table and the whole `site_settings` table are taken (a snapshot
// in a file OUTSIDE the project folder); at the end the settings are put back, the block, the throw-away data and the staff member are deleted and the checksums are compared (a watchdog puts the data back after 15
// minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/tbl-1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node tbl-1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const artisan = (args) => {
    const r = spawnSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) { throw new Error(`artisan ${args.join(' ')} failed (${r.status}): ` + ((r.stdout ?? '') + (r.stderr ?? '')).slice(-600)); }
    return (r.stdout ?? '').trim();
};
const php = (code) => {
    const out = artisan(['tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')]);
    if (out === '') { throw new Error('php produced no output'); }
    return out.split('\n').pop();
};

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['products' => $sum('catalog_products'), 'variations' => $sum('catalog_variations'), 'stock' => $sum('stock_levels'), 'prices' => $sum('pricing_price_list_items'), 'pivots' => $sum('catalog_product_categories').$sum('catalog_product_tags'), 'categories' => $sum('catalog_categories'), 'settings' => md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all())), 'lists' => $sum('pricing_price_lists'), 'jobs' => DB::table('jobs')->count(), 'staff' => DB::table('staff')->count(), 'threshold' => md5(json_encode(app(App\Services\FreeShippingThreshold::class)->refresh()))]);`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'tbl1-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('pricing_price_list_items')->where('target_type', 'variation')->whereIn('target_id', $vids->map(fn ($v) => (string) $v))->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_categories')->where('slug', 'tbl1-audit-cat')->delete();
DB::table('jobs')->where('payload', 'like', '%RefreshProductSortPrices%')->delete();
echo 'cleaned';`;

// a throw-away category «TBL1 audit cat» with five products (simple, regular 100.00): 1 manual sale 80.00, new, stock 5; 2 new, stock 5; 3 created 60 days ago, stock 2; 4 created 60 days ago, stock 9; 5 created 60 days ago, stock 0 (sold out)
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$old = now()->subDays(60);
$class = DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class');
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
$regular = DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
$sample = DB::table('pricing_price_list_items')->where('price_list_id', $regular)->first();
$basis = $sample->price_tax_rate_basis_points ?? 0; $incl = $sample->price_tax_inclusive ?? 1;
$cat = DB::table('catalog_categories')->insertGetId(['name' => 'TBL1 audit cat', 'slug' => 'tbl1-audit-cat', 'created_at' => $now, 'updated_at' => $now]);
$stock = [1 => 5, 2 => 5, 3 => 2, 4 => 9, 5 => 0];
$ids = [];
for ($i = 1; $i <= 5; $i++) {
  $at = $i <= 2 ? $now : $old;
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'TBL1 audit '.$i, 'slug' => 'tbl1-audit-'.$i, 'base_sku' => 'TBL1AUDIT-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group, 'created_at' => $at, 'updated_at' => $at, 'timeline_at' => $at->copy()->addSeconds(10 - $i), 'is_available' => $stock[$i] > 0 ? 1 : 0, 'price_from_minor' => 10000]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'universal', 'status' => 'active', 'attribute_signature' => hash('sha256', 'tbl1-'.$i), 'sku' => 'TBL1AUDIT-'.$i.'-0', 'is_visible' => 0, 'is_purchasable' => 1, 'shipping_class' => $class, 'created_at' => $at, 'updated_at' => $at]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => $stock[$i], 'created_at' => $now, 'updated_at' => $now]);
  DB::table('pricing_price_list_items')->insert(['price_list_id' => $regular, 'target_type' => 'variation', 'target_id' => (string) $vid, 'min_quantity' => 1, 'price_amount_minor' => 10000, 'price_currency' => 'EUR', 'price_tax_rate_basis_points' => $basis, 'price_tax_inclusive' => $incl, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  $ids[] = $pid;
  if ($i === 1) { app(App\Services\ProductPricingAndStock::class)->writeSalePrice((string) $vid, '80.00'); }
}
echo json_encode(['ids' => $ids, 'cat' => $cat]);`;

// the shop shows sold-out products for the run (so the sold-out card is there); the settings table is put back from the snapshot
const SHOW_SOLD_OUT = String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('catalog.hide_sold_out', '0'); echo 'ok';`;

// the badge set, published the way the zone publishes it (BadgeStore). `over`: rows by index merged over the defaults (0 sale, 1 new, 2 low_stock, 3 free_shipping); `custom`: custom rows appended
const BADGES = (over = {}, custom = []) => String.raw`
use App\Storefront\Badges\BadgeSet; use App\Storefront\Badges\BadgeStore;
$w = BadgeSet::defaults();
${Object.entries(over).map(([i, row]) => `$w['badges'][${i}] = array_replace($w['badges'][${i}], json_decode('${JSON.stringify(row)}', true));`).join(' ')}
${custom.map((row) => `$w['badges'][] = json_decode('${JSON.stringify(row)}', true);`).join(' ')}
[$c, $e] = BadgeSet::validate($w);
if ($e !== []) { throw new Exception(json_encode($e)); }
BadgeStore::publish($c);
echo 'ok';`;

// the cached free-shipping threshold is set directly (the shop's real zones and methods are not touched); the end of the run recomputes it from the real data
const THRESHOLD = (minor) => String.raw`Illuminate\Support\Facades\Cache::forever(App\Services\FreeShippingThreshold::KEY, ['minor' => ${minor}, 'currency' => 'EUR']); echo 'ok';`;
// the settings rows are kept in a file (a command line is too short for them)
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; use App\Storefront\Filters\FilterCatalog; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } FilterCatalog::forget(); app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh(); app(App\Services\FreeShippingThreshold::class)->refresh(); echo 'restored';`;


// a throw-away staff member (the Administrator role) for the admin screen; deleted at the end
const EMAIL = 'tbl1-audit@example.test';
const PASSWORD = 'TBL1-' + Math.random().toString(36).slice(2, 12) + 'Aa1!';
const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'TBL1 Audit', $role));
echo 'ok';`;
const DELETE_STAFF = String.raw`use Illuminate\Support\Facades\DB; DB::table('staff')->where('email', '${EMAIL}')->delete(); echo 'ok';`;


const BLOCK_TITLE = 'TBL1 audit size table';
const DELETE_BLOCK = String.raw`use Illuminate\Support\Facades\DB; DB::table('html_blocks')->where('title', '${BLOCK_TITLE}')->delete(); echo 'ok';`;
const BLOCK_STATE = String.raw`use Illuminate\Support\Facades\DB; $b = DB::table('html_blocks')->where('title', '${BLOCK_TITLE}')->first(); echo json_encode($b ? ['id' => $b->id, 'body' => $b->body] : null);`;

// the block goes into a product section («HTML блокове») shown as an accordion on the throw-away product's page
const PUBLISH_SECTION = (blockId) => String.raw`
use App\Settings\Contracts\SiteSettingsRepository; use App\Storefront\ProductSummary\ProductSummaryCompiler; use App\Storefront\ProductSummary\ProductSummaryLayout; use App\Storefront\Sections\SectionSet; use App\Storefront\Sections\SectionStore;
[$clean, $errors] = SectionSet::validate(['sections' => [['key' => '66666666-6666-4666-8666-666666666666', 'title' => 'Размери', 'source' => 'blocks', 'blocks' => [${blockId}]]]]);
if ($errors !== []) { throw new Exception(json_encode($errors)); }
SectionStore::publish($clean);
$layout = ProductSummaryLayout::default();
$layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => true]];
app(ProductSummaryCompiler::class)->publish($layout, app(SiteSettingsRepository::class));
echo 'ok';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };

let before = null; let snapshot = false; let watchdog = null;
const putBack = () => { try { php(CLEANUP); } catch (e) { console.error('cleanup failed ' + e); } try { php(DELETE_BLOCK); } catch (e) { console.error('block cleanup failed ' + e); } try { php(DELETE_STAFF); } catch (e) { console.error('staff cleanup failed ' + e); } if (snapshot) { php(RESTORE()); } };

try {
    before = JSON.parse(php(SUMS));
    snapshot = php(SNAPSHOT) === 'saved';
    watchdog = setTimeout(() => { putBack(); console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    const world = JSON.parse(php(SETUP));
    check('the throw-away category and five products were created', world.ids.length === 5);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name, options = {}) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, ...options }); };
    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); });
    };

    // ================================================================ ADMIN: a 4×6 size table with the toolbar
    {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1300 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage(); watch(page, 'admin');
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        await page.goto(BASE + '/admin/html-blocks/create', { waitUntil: 'networkidle' });
        await page.fill('input[id="form.title"]', BLOCK_TITLE);

        const tool = (label) => page.locator(`.fi-fo-rich-editor-tool[aria-label="${label}"]`).first();
        check('the toolbar offers the table buttons (insert, add / delete column and row, header row)', (await Promise.all(['Table', 'Add column after', 'Delete column', 'Add row below', 'Delete row', 'Toggle header row'].map((l) => tool(l).count()))).every((c) => c > 0));
        await page.locator('.tiptap').first().click();
        await tool('Table').click();
        await sleep(400);
        for (let i = 0; i < 3; i++) { await tool('Add column after').click(); await sleep(150); }
        for (let i = 0; i < 2; i++) { await tool('Add row below').click(); await sleep(150); }
        const dims = await page.locator('.tiptap table').first().evaluate((t) => [t.rows.length, t.rows[0].cells.length]);
        check('the toolbar made a 4 × 6 table', dims[0] === 4 && dims[1] === 6, JSON.stringify(dims));

        // fill the cells: click the first cell, then Tab through them
        const rows = [['Размер', 'XS', 'S', 'M', 'L', 'XL'], ['Гърди (см)', '82', '88', '94', '100', '106'], ['Талия (см)', '64', '70', '76', '82', '88'], ['Ханш (см)', '90', '96', '102', '108', '114']];
        await page.locator('.tiptap table tr').first().locator('th, td').first().click();
        for (const [r, row] of rows.entries()) { for (const [c, cell] of row.entries()) { await page.keyboard.type(cell); (r < rows.length - 1 || c < row.length - 1) && await page.keyboard.press('Tab'); } }
        await sleep(300);
        const texts = await page.locator('.tiptap table').first().evaluate((t) => [...t.rows].map((r) => [...r.cells].map((c) => c.textContent.trim())));
        check('the cells hold the typed sizes', JSON.stringify(texts) === JSON.stringify(rows), JSON.stringify(texts));
        await shot(page, 'tbl1-admin-editor');

        await page.getByRole('button', { name: /^(Създаване|Създай)$/ }).first().click();
        await page.waitForURL(/\/admin\/html-blocks(\/\d+\/edit)?$/, { timeout: 30000 }).catch(() => {});
        await sleep(1500);
        const stored = JSON.parse(php(BLOCK_STATE));
        report.stored = stored;
        check('the block was saved with its table: 4 rows, header cells, no style / class / width in the stored body', stored !== null && (stored.body.match(/<tr>/g) ?? []).length === 4 && stored.body.includes('<th') && ! /style=|class=|width=|colwidth/.test(stored.body) && ! stored.body.includes('sf-table-wrap'), stored ? stored.body.slice(0, 400) : 'no block');
        await ctx.close();
    }

    // ================================================================ STOREFRONT: the table in an accordion section
    const stored = JSON.parse(php(BLOCK_STATE));
    php(PUBLISH_SECTION(stored.id));
    const lum = async (page, buf) => page.evaluate(async (b64) => { const img = await createImageBitmap(await (await fetch('data:image/png;base64,' + b64)).blob()); const c = new OffscreenCanvas(img.width, img.height); const g = c.getContext('2d'); g.drawImage(img, 0, 0); const d = g.getImageData(0, 0, img.width, img.height).data; let s = 0; for (let i = 0; i < d.length; i += 4) s += (d[i] + d[i + 1] + d[i + 2]) / 3; return s / (d.length / 4); }, buf.toString('base64'));
    const URL1 = BASE + '/product/tbl1-audit-1';

    for (const width of [1440, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: 1200 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
        ctx.setDefaultTimeout(30000);
        const page = await ctx.newPage(); watch(page, String(width));
        await page.goto(URL1, { waitUntil: 'networkidle' });
        await page.locator('details.sf-psec').first().evaluate((d) => { d.open = true; });
        await sleep(400);
        const wrap = page.locator('.sf-table-wrap').first();
        check(`${width}: the table is printed inside .sf-table-wrap in the accordion section`, (await page.locator('details.sf-psec .sf-psec-body .sf-table-wrap > table').count()) === 1);
        const m = await wrap.evaluate((el) => {
            const probe = document.createElement('div'); probe.style.borderColor = 'var(--color-neutral-200)'; probe.style.borderStyle = 'solid'; document.body.append(probe); const token = getComputedStyle(probe).borderTopColor; probe.remove(); const t = el.querySelector('table'); const cs = getComputedStyle(t); const th = el.querySelector('th:not(:first-child)'); const td = el.querySelector('td'); const tcs = getComputedStyle(td);
            return { box: el.clientWidth, content: el.scrollWidth, page: document.documentElement.scrollWidth, view: document.documentElement.clientWidth, collapse: cs.borderCollapse, font: cs.fontSize, tdBorder: tcs.borderTopWidth + ' ' + tcs.borderTopColor, token: '1px ' + token, tdPad: tcs.paddingTop + ' ' + tcs.paddingLeft, thWeight: getComputedStyle(th).fontWeight, thAlign: getComputedStyle(th).textAlign, thBg: getComputedStyle(th).backgroundColor, firstSticky: getComputedStyle(el.querySelector('tr > :first-child')).position, tableW: t.getBoundingClientRect().width };
        });
        report[`m-${width}`] = m;
        check(`${width}: LK-1 look: collapsed 1px hairlines in the border token (#e5e5e5 untouched), 14px text, padding 8px 12px, header cells weight 500, left, no coloured background`, m.collapse === 'collapse' && m.font === '14px' && m.tdBorder === m.token && m.tdPad === '8px 12px' && m.thWeight === '500' && m.thAlign === 'left' && m.thBg === 'rgba(0, 0, 0, 0)', JSON.stringify(m));
        check(`${width}: no sideways scroll of the PAGE`, m.page <= m.view + 1, `${m.page} / ${m.view}`);

        if (width === 390) {
            check('390: the table is wider than its box and scrolls INSIDE the box', m.content > m.box + 4, `${m.content} > ${m.box}`);
            const box = await wrap.boundingBox();
            const edge = { x: box.x + box.width - 14, y: box.y, width: 14, height: Math.min(box.height, 120) };
            const first = () => wrap.evaluate((el) => { const c = el.querySelector('tbody tr:nth-child(2) > :first-child').getBoundingClientRect(); const second = el.querySelector('tbody tr:nth-child(2) > :nth-child(2)').getBoundingClientRect(); const w = el.getBoundingClientRect(); return { first: c.left - w.left, second: second.left - w.left, sl: el.scrollLeft }; });
            const a = await first();
            const lumBefore = await lum(page, await page.screenshot({ clip: edge }));
            await shot(page, 'tbl1-390-before', { clip: { x: 0, y: Math.max(0, box.y - 60), width: 390, height: Math.min(520, box.height + 140) } });
            await wrap.evaluate((el) => { el.scrollLeft = 70; });
            await sleep(300);
            const b = await first();
            await shot(page, 'tbl1-390-scrolled', { clip: { x: 0, y: Math.max(0, box.y - 60), width: 390, height: Math.min(520, box.height + 140) } });
            await wrap.evaluate((el) => { el.scrollLeft = el.scrollWidth; });
            await sleep(300);
            const lumEnd = await lum(page, await page.screenshot({ clip: edge }));
            report.sticky = { a, b }; report.lum = { lumBefore, lumEnd };
            check('390: the first column stays in place while the rest scrolls (its left edge is the same before and after)', Math.abs(a.first - b.first) < 0.6 && b.sl > 40 && Math.abs(a.second - b.second) > 40, JSON.stringify({ a, b }));
            check('390: the soft shadow on the right edge is visible before scrolling and gone at the end', lumBefore < lumEnd - 3, `edge brightness ${lumBefore.toFixed(1)} before, ${lumEnd.toFixed(1)} at the end`);
        } else {
            // the right column is narrow: the six columns do not fit even at 1440, so the box scrolls here too; a table that FITS (injected here: two columns) looks plain: no scroll, no shadow
            const box = await wrap.boundingBox();
            await shot(page, 'tbl1-1440', { clip: { x: Math.max(0, box.x - 40), y: Math.max(0, box.y - 80), width: Math.min(700, 1440 - Math.max(0, box.x - 40)), height: Math.min(420, box.height + 160) } });
            const fits = await page.evaluate(() => { const w = document.createElement('div'); w.className = 'sf-table-wrap'; w.id = 'tbl1-fits'; w.innerHTML = '<table><tr><th>S</th><td>90</td></tr></table>'; document.querySelector('.sf-psec-body').append(w); const r = w.getBoundingClientRect(); return { box: w.clientWidth, content: w.scrollWidth, x: r.x, y: r.y + window.scrollY, w: r.width, h: r.height }; });
            check('1440: a table that fits its box does not scroll', fits.content <= fits.box + 1, JSON.stringify(fits));
            const lumFits = await lum(page, await page.locator('#tbl1-fits').screenshot({ clip: { x: fits.w - 14, y: 0, width: 14, height: Math.max(1, fits.h) } }));
            check('1440: and its right edge is plain (no shadow)', lumFits > 240, String(lumFits));
        }
        await ctx.close();
    }
    check('no console errors and no 5xx', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
    check('the script ran to the end', false, String(e));
} finally {
    clearTimeout(watchdog);
    putBack();
    const after = JSON.parse(php(SUMS));
    for (const key of Object.keys(before ?? {})) { check(`checksum restored: ${key}`, before[key] === after[key], `${before[key]} -> ${after[key]}`); }
    await browser.close();
}

const failed = steps.filter((s) => ! s.ok);
console.log(JSON.stringify({ steps: steps.length, failed: failed.length, report, shots }, null, 1));
failed.length && console.log(failed.map((s) => `FAIL ${s.name} ${s.detail}`).join('\n'));
process.exit(failed.length === 0 ? 0 : 1);
