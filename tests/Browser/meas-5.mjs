// A REAL-BROWSER check of MEAS-5: the product-page section source «Таблица с размери».
//   The script publishes ONE section «Размери» with the new source (and the accordion on the product page), then looks at three throw-away products: a SIMPLE phone with a «Общи размери» template (the section shows the list
//   «Дебелина 7,8 мм · Екран 6,1 инч · Тегло 172 г»), a VARIABLE shirt with a «По размер» template of five measures (the section shows the table, the empty size row is dropped, numbers in bg format) and a plain product
//   with no template (NO section at all). At 390 px the table scrolls INSIDE its own box (TBL-1), the first column stays in place and the page does not scroll sideways. Screenshots: the list, the table at 1440, the table at 390.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run the checksums of the catalog, pricing, stock, section-text and activity-log tables and the whole `site_settings` table (the design publish keys
// the script touches live there) are taken; the settings are snapshotted to a file OUTSIDE the project folder; at the end everything the script created is deleted, the settings are put back and the checksums are compared
// (a watchdog does the same after 15 minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/meas-5.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node meas-5.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const artisan = (args) => {
    const r = spawnSync('php', ['artisan', ...args], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) { throw new Error(`artisan ${args.join(' ')} failed (${r.status}): ` + ((r.stdout ?? '') + (r.stderr ?? '')).slice(-600)); }
    // tinker prints an exception on stderr: when stdout is empty, show it (the error is the answer)
    return (r.stdout ?? '').trim() || (r.stderr ?? '').trim();
};
// the snippets are long and a Windows command line is limited, so a snippet goes to a file (in OUT) that tinker includes
let snippetNo = 0;
const php = (code) => {
    const file = join(OUT, '_snippet' + (++snippetNo % 2) + '.php').replace(/\\/g, '/');
    writeFileSync(file, '<?php\n' + code);
    const out = artisan(['tinker', '--execute', `include '${file}';`]);
    if (out === '') { throw new Error('php produced no output'); }
    return out.split('\n').pop();
};

const TABLES = ['catalog_measures', 'catalog_measurement_templates', 'catalog_measurement_template_measures', 'catalog_measurement_values', 'catalog_product_measurement_templates', 'catalog_products', 'catalog_variations', 'catalog_attribute_definitions', 'catalog_attribute_values', 'catalog_product_attributes', 'catalog_product_axis_values', 'catalog_variation_attribute_values', 'catalog_sku_sequence', 'pricing_product_costs', 'pricing_price_list_items', 'stock_levels', 'product_section_texts', 'activity_log'];
const SUMS = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
$sum = function ($t) { $q = DB::table($t); if (Schema::hasColumn($t, 'id')) { $q->orderBy('id'); } return md5(json_encode($q->get()->map(fn ($r) => (array) $r)->all())); };
$out = []; foreach (${JSON.stringify(TABLES).replace(/"/g, "'")} as $t) { $out[$t] = $sum($t); }
$out['settings'] = md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode($out);`;
const MAX_LOG = String.raw`echo (int) Illuminate\Support\Facades\DB::table('activity_log')->max('id');`;

let maxLog = 0;
const CLEANUP = () => String.raw`
use Illuminate\Support\Facades\DB;
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$pids = DB::table('catalog_products')->where('slug', 'like', 'meas5-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('catalog_measurement_values')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_measurement_templates')->whereIn('product_id', $pids)->delete();
DB::table('pricing_price_list_items')->where(fn ($q) => $q->where('target_type', 'product')->whereIn('target_id', $pids))->orWhere(fn ($q) => $q->where('target_type', 'variation')->whereIn('target_id', $vids))->delete();
DB::table('pricing_product_costs')->whereIn('priceable_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_axis_values')->whereIn('product_id', $pids)->delete();
DB::table('product_section_texts')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$tids = DB::table('catalog_measurement_templates')->where('name', 'like', 'MEAS5 %')->pluck('id');
DB::table('catalog_product_measurement_templates')->whereIn('template_id', $tids)->delete();
DB::table('catalog_measurement_template_measures')->whereIn('template_id', $tids)->delete();
DB::table('catalog_measurement_templates')->whereIn('id', $tids)->delete();
$mids = DB::table('catalog_measures')->where('name', 'like', 'MEAS5 %')->pluck('id');
DB::table('catalog_measurement_values')->whereIn('measure_id', $mids)->delete();
DB::table('catalog_measurement_template_measures')->whereIn('measure_id', $mids)->delete();
DB::table('catalog_measures')->whereIn('id', $mids)->delete();
$def = DB::table('catalog_attribute_definitions')->where('code', 'like', 'meas5-audit-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $def)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $def)->delete();
DB::table('activity_log')->where('id', '>', ${maxLog})->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo 'cleaned';`;

// phone (simple, «Общи размери»), shirt (variable, size flagged S/M/L, «По размер» with 5 measures; numbers for S and M only: L is an empty row and is dropped), plain (no template). All active and visible with a price and stock.
const SETUP = String.raw`
use EasyCo\Catalog\AttributeDefinition; use EasyCo\Catalog\AttributeValue; use EasyCo\Catalog\Enums\AttributeType; use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository; use EasyCo\Catalog\Contracts\AttributeValueRepository; use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Measure; use EasyCo\Catalog\MeasurementTemplate; use EasyCo\Catalog\Product; use EasyCo\Catalog\VariationAxis; use Illuminate\Support\Facades\DB;
$ms = []; foreach ([['MEAS5 Дебелина', 'mm'], ['MEAS5 Екран', 'in'], ['MEAS5 Тегло', 'g'], ['MEAS5 Гръдна обиколка', 'cm'], ['MEAS5 Талия', 'cm'], ['MEAS5 Дължина', 'cm'], ['MEAS5 Дължина на ръкава', 'cm'], ['MEAS5 Ширина на раменете', 'cm']] as $i => [$n, $u]) { $m = Measure::create($n, $u, 900 + $i); app(App\Services\MeasureWriter::class)->save($m); $ms[$n] = (string) $m->id(); }
$tpl = function ($name, $names, $kind) use ($ms) { $t = MeasurementTemplate::create($name, array_map(fn ($n) => $ms[$n], $names), 900, $kind); app(App\Services\MeasurementTemplateWriter::class)->save($t); return (string) $t->id(); };
$phones = $tpl('MEAS5 Телефони', ['MEAS5 Дебелина', 'MEAS5 Екран', 'MEAS5 Тегло'], MeasurementTemplateKind::SINGLE);
$shirts = $tpl('MEAS5 Ризи', ['MEAS5 Гръдна обиколка', 'MEAS5 Талия', 'MEAS5 Дължина', 'MEAS5 Дължина на ръкава', 'MEAS5 Ширина на раменете'], MeasurementTemplateKind::BY_SIZE);
$size = new AttributeDefinition(id: null, code: 'meas5-audit-size', name: 'Размер', type: AttributeType::SELECT, isSize: true); app(AttributeDefinitionRepository::class)->save($size);
$sizes = []; foreach (['S', 'M', 'L'] as $i => $v) { $x = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: $v, sortOrder: $i); app(AttributeValueRepository::class)->save($x); $sizes[] = $x; }
$class = (string) DB::table('shipping_classes')->where('is_default', 1)->value('code');
$shirt = Product::createVariable('MEAS5 Риза', 'MEAS5AUDIT-V', 'meas5-audit-shirt'); $shirt->declareVariationAxes([new VariationAxis($size, $sizes)]);
foreach ($sizes as $s) { $shirt->addStandardVariation([$size->id() => $s->id()], 'MEAS5AUDIT-V-'.$s->value()); }
app(ProductRepository::class)->save($shirt);
$phone = Product::createSimple('MEAS5 Телефон', 'MEAS5AUDIT-P', 'meas5-audit-phone'); app(ProductRepository::class)->save($phone);
$plain = Product::createSimple('MEAS5 Обикновен', 'MEAS5AUDIT-N', 'meas5-audit-plain'); app(ProductRepository::class)->save($plain);
$ids = [$shirt->id(), $phone->id(), $plain->id()];
$group = DB::table('catalog_product_groups')->orderBy('id')->value('id');
DB::table('catalog_products')->whereIn('id', $ids)->update(['status' => 'active', 'catalog_visibility' => 'visible', 'product_group_id' => $group]);
DB::table('catalog_variations')->whereIn('product_id', $ids)->update(['shipping_class' => $class, 'status' => 'active', 'is_visible' => 1]);
$pricing = app(App\Services\ProductPricingAndStock::class);
foreach (DB::table('catalog_variations')->whereIn('product_id', $ids)->pluck('id') as $vid) { $pricing->writeRegularPrice((string) $vid, '25.00'); DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => now(), 'updated_at' => now()]); }
$w = app(App\Services\ProductMeasurementsWriter::class);
$w->assignTemplate((string) $phone->id(), $phones); $w->saveGrid((string) $phone->id(), ['product' => [$ms['MEAS5 Дебелина'] => 780, $ms['MEAS5 Екран'] => 610, $ms['MEAS5 Тегло'] => 17200]]);
$w->assignTemplate((string) $shirt->id(), $shirts);
$w->saveGrid((string) $shirt->id(), [(string) $sizes[0]->id() => [$ms['MEAS5 Гръдна обиколка'] => 9000, $ms['MEAS5 Талия'] => 7000, $ms['MEAS5 Дължина'] => 6550, $ms['MEAS5 Дължина на ръкава'] => 6000, $ms['MEAS5 Ширина на раменете'] => 4500], (string) $sizes[1]->id() => [$ms['MEAS5 Гръдна обиколка'] => 9550, $ms['MEAS5 Талия'] => 7550, $ms['MEAS5 Дължина'] => 6700, $ms['MEAS5 Дължина на ръкава'] => 6100, $ms['MEAS5 Ширина на раменете'] => 4650]]);
// the section «Размери» with the new source + the accordion on the product page, published the way the zones publish them
[$clean, $errors] = App\Storefront\Sections\SectionSet::validate(['sections' => [['key' => '99999999-9999-4999-8999-999999999999', 'title' => 'Размери', 'source' => 'measurements']]]); if ($errors !== []) { throw new Exception(json_encode($errors)); }
App\Storefront\Sections\SectionStore::publish($clean);
$layout = App\Storefront\ProductSummary\ProductSummaryLayout::default(); $layout['components'][] = ['type' => 'accordion', 'settings' => ['first_open' => true]];
app(App\Storefront\ProductSummary\ProductSummaryCompiler::class)->publish($layout, app(App\Settings\Contracts\SiteSettingsRepository::class));
echo json_encode(['phone' => '/product/meas5-audit-phone', 'shirt' => '/product/meas5-audit-shirt', 'plain' => '/product/meas5-audit-plain']);`;

const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } echo 'restored';`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };

let before = null; let snapshot = false; let watchdog = null;
const putBack = () => { try { php(CLEANUP()); } catch (e) { console.error('cleanup failed ' + e); } if (snapshot) { php(RESTORE()); } };

try {
    before = JSON.parse(php(SUMS));
    maxLog = Number(php(MAX_LOG));
    snapshot = php(SNAPSHOT) === 'saved';
    watchdog = setTimeout(() => { putBack(); console.error('WATCHDOG: the run took more than 15 minutes; the data was put back'); process.exit(3); }, 15 * 60 * 1000);
    php(CLEANUP()); // a leftover of an earlier, interrupted run
    const world = JSON.parse(php(SETUP));
    report.world = world;

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name, full = false) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); };
    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { if (r.status() >= 500) { consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); } });
    };
    const open = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); await sleep(600); };
    const section = (page) => page.locator('details.sf-psec', { has: page.locator('summary', { hasText: /^\s*Размери\s*$/ }) });
    const opened = async (page) => { const d = section(page); if (! (await d.getAttribute('open') !== null)) { await d.locator('summary').click(); await sleep(500); } return d; };

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
    ctx.setDefaultTimeout(30000);
    const page = await ctx.newPage(); watch(page, 'shop');

    // ---- the simple phone: the list
    await open(page, world.phone);
    check('the phone page has the section «Размери»', (await section(page).count()) === 1);
    const d1 = await opened(page);
    check('it is a definition list, not a table', (await d1.locator('dl.sf-meas-list').count()) === 1 && (await d1.locator('table').count()) === 0);
    const text = (await d1.locator('dl.sf-meas-list').innerText()).replace(/ /g, ' ').replace(/\s+/g, ' ');
    check('it lists Дебелина 7,8 мм, Екран 6,1 инч, Тегло 172 г in the template order', /MEAS5 Дебелина 7,8 мм MEAS5 Екран 6,1 инч MEAS5 Тегло 172 г/.test(text), text);
    await d1.scrollIntoViewIfNeeded(); await sleep(300);
    await shot(page, 'meas5-1-phone-list-1440');

    // ---- the variable shirt: the table
    await open(page, world.shirt);
    const d2 = await opened(page);
    const table = d2.locator('.sf-table-wrap > table.sf-meas');
    check('the shirt has the table inside the TBL-1 wrapper', (await table.count()) === 1);
    check('the caption is for assistive tech only and names the product', /Таблица с размери: MEAS5 Риза/.test(await table.locator('caption').textContent()) && (await table.locator('caption').getAttribute('class')) === 'sf-sr-only');
    const head = (await table.locator('thead th').allInnerTexts()).map((t) => t.trim());
    check('the header is the axis name then «мярка, единица» in the template order', head.join('|') === 'Размер|MEAS5 Гръдна обиколка, см|MEAS5 Талия, см|MEAS5 Дължина, см|MEAS5 Дължина на ръкава, см|MEAS5 Ширина на раменете, см', head.join('|'));
    const rows = (await table.locator('tbody tr').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
    check('two rows (S, M): the empty size L is dropped; numbers in the bg format', rows.length === 2 && rows[0] === 'S 90 70 65,5 60 45' && rows[1] === 'M 95,5 75,5 67 61 46,5', rows.join(' / '));
    check('the row headers are <th scope=row>', (await table.locator('tbody th[scope=row]').count()) === 2);
    await d2.scrollIntoViewIfNeeded(); await sleep(300);
    await shot(page, 'meas5-2-shirt-table-1440');

    // ---- a product without a template: no section at all
    await open(page, world.plain);
    check('a product without numbers shows no section', (await section(page).count()) === 0 && ! (await page.content()).includes('sf-meas'));

    // ---- 390 px: the table scrolls inside its box, the first column stays, the page does not scroll sideways
    await page.setViewportSize({ width: 390, height: 900 });
    await open(page, world.shirt);
    const d3 = await opened(page);
    await d3.scrollIntoViewIfNeeded(); await sleep(300);
    const geo = await page.evaluate(() => {
        const box = document.querySelector('details.sf-psec[open] .sf-table-wrap');
        const first = box.querySelector('tbody th');
        const before = first.getBoundingClientRect().left;
        box.scrollLeft = 140;
        return { boxScroll: box.scrollWidth, boxClient: box.clientWidth, moved: box.scrollLeft, stickyBefore: before, stickyAfter: first.getBoundingClientRect().left, page: document.documentElement.scrollWidth, inner: window.innerWidth };
    });
    report.geo390 = geo;
    check('390 px: the table scrolls inside its own box', geo.boxScroll > geo.boxClient && geo.moved > 0, JSON.stringify(geo));
    check('390 px: the first column stays in place (sticky)', Math.abs(geo.stickyAfter - geo.stickyBefore) < 1.5, JSON.stringify(geo));
    check('390 px: the page does not scroll sideways', geo.page <= geo.inner + 1, JSON.stringify(geo));
    await shot(page, 'meas5-3-shirt-table-390');
    await open(page, world.phone);
    await opened(page);
    check('390 px: the list stacks (label above value) and the page does not scroll sideways', await page.evaluate(() => { const row = document.querySelector('.sf-meas-list > div'); return getComputedStyle(row).flexDirection === 'column' && document.documentElement.scrollWidth <= window.innerWidth + 1; }));

    await ctx.close();
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
