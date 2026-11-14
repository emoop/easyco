// A REAL-BROWSER check of MEAS-4: the product tab «Размери» (two template kinds, units g / kg).
//   Flow A (simple product, «Общи размери» template «Телефони»: Дебелина мм, Екран инч, Тегло г): the picker offers it, the tab is a vertical list (no table), the merchant types 7,8 / 6.1 / 172 with Enter moving
//   down, saves, reloads and sees «7,8», «6,1», «172».
//   Flow B (variable shirt, size attribute flagged, colour axis too): pick «Ризи» (По размер), type numbers with Enter DOWN a column and Tab ACROSS a row (Enter never submits), a bad value 9,555 shows the error on
//   THAT cell and saves nothing (a product name changed in the same submit is not saved either), «Копирай първия ред във всички» fills only the empty cells, save, reload shows bg-formatted numbers; switching to
//   another template shows the warning and the hidden count, switching back brings the numbers back; at 390 px the grid scrolls INSIDE its own box with a sticky first column and the page does not scroll sideways.
//   Screenshots: the single list filled, the by_size grid filled, the cell error, the switch warning, the 390 px grid.
// NOT part of `php artisan test`: run by hand against the dev site (the MEAS-4 migration must be applied there). BEFORE the run the checksums of the catalog, pricing, stock, section-text, activity-log, staff and role
// tables and the whole `site_settings` table are taken (a snapshot in a file OUTSIDE the project folder); at the end everything the script created (measures, templates, attributes, both products with their variations,
// prices, stock, the staff member, their activity-log rows) is deleted and the checksums are compared (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/meas-4.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node meas-4.mjs'); process.exit(2); }
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

// like php(), but an empty answer is a valid answer (no template assigned)
const phpMaybe = (code) => { const out = artisan(['tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')]); return out === '' ? '' : out.split('\n').pop(); };

const TABLES = ['catalog_measures', 'catalog_measurement_templates', 'catalog_measurement_template_measures', 'catalog_measurement_values', 'catalog_product_measurement_templates', 'catalog_products', 'catalog_variations', 'catalog_attribute_definitions', 'catalog_attribute_values', 'catalog_product_attributes', 'catalog_product_axis_values', 'catalog_variation_attribute_values', 'catalog_sku_sequence', 'catalog_product_categories', 'catalog_product_tags', 'catalog_product_media', 'catalog_variation_media', 'pricing_product_costs', 'pricing_price_list_items', 'stock_levels', 'product_section_texts', 'activity_log', 'staff', 'staff_roles'];
const SUMS = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
$sum = function ($t) { $q = DB::table($t); if (Schema::hasColumn($t, 'id')) { $q->orderBy('id'); } return md5(json_encode($q->get()->map(fn ($r) => (array) $r)->all())); };
$out = []; foreach (${JSON.stringify(TABLES).replace(/"/g, "'")} as $t) { $out[$t] = $sum($t); }
$out['settings'] = md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode($out);`;
const MAX_LOG = String.raw`echo (int) Illuminate\Support\Facades\DB::table('activity_log')->max('id');`;

const EMAIL = 'meas4-audit@example.test';
const PASSWORD = 'MEAS4-' + Math.random().toString(36).slice(2, 12) + 'Aa1!';

let maxLog = 0;
// activity_log: only this run's own rows (id above the start maximum) and only the entity types the run creates; never with maxLog unread or 0 (that would match the whole log)
const logCleanup = () => {
    if (! (maxLog > 0)) { console.error('WARNING: activity_log not cleaned up: maxLog is ' + maxLog + ' (not read yet, or the log was empty at start); no rows deleted'); return ''; }
    return `DB::table('activity_log')->where('id', '>', ${maxLog})->whereIn('entity_type', ['product', 'measure', 'measurement_template', 'attribute_definition'])->delete();`;
};
const CLEANUP = () => String.raw`
use Illuminate\Support\Facades\DB;
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$pids = DB::table('catalog_products')->where('slug', 'like', 'meas4-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('catalog_measurement_values')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_measurement_templates')->whereIn('product_id', $pids)->delete();
DB::table('pricing_price_list_items')->where(fn ($q) => $q->where('target_type', 'product')->whereIn('target_id', $pids))->orWhere(fn ($q) => $q->where('target_type', 'variation')->whereIn('target_id', $vids))->delete();
DB::table('pricing_product_costs')->whereIn('priceable_id', $vids)->delete();
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_media')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_axis_values')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_media')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('product_section_texts')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$tids = DB::table('catalog_measurement_templates')->where('name', 'like', 'MEAS4 %')->pluck('id');
DB::table('catalog_product_measurement_templates')->whereIn('template_id', $tids)->delete();
DB::table('catalog_measurement_template_measures')->whereIn('template_id', $tids)->delete();
DB::table('catalog_measurement_templates')->whereIn('id', $tids)->delete();
$mids = DB::table('catalog_measures')->where('name', 'like', 'MEAS4 %')->pluck('id');
DB::table('catalog_measurement_values')->whereIn('measure_id', $mids)->delete();
DB::table('catalog_measurement_template_measures')->whereIn('measure_id', $mids)->delete();
DB::table('catalog_measures')->whereIn('id', $mids)->delete();
$def = DB::table('catalog_attribute_definitions')->where('code', 'like', 'meas4-audit-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $def)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $def)->delete();
DB::table('staff')->where('email', '${EMAIL}')->delete();
${logCleanup()}
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo 'cleaned';`;

// measures: phones (Дебелина мм, Екран инч, Тегло г) and shirts (Гръдна обиколка, Талия, Дължина, all cm); templates «Телефони» (single), «Ризи» (by_size, 3 measures), «Рокли» (by_size, Талия only);
// a throw-away size attribute (flagged is_size) with S, M, L; a SIMPLE product (the phone) and a VARIABLE product (the shirt: colour x size)
const SETUP = String.raw`
use EasyCo\Catalog\AttributeDefinition; use EasyCo\Catalog\AttributeValue; use EasyCo\Catalog\Enums\AttributeType; use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository; use EasyCo\Catalog\Contracts\AttributeValueRepository; use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Measure; use EasyCo\Catalog\MeasurementTemplate; use EasyCo\Catalog\Product; use EasyCo\Catalog\VariationAxis; use Illuminate\Support\Facades\DB;
$ms = []; foreach ([['MEAS4 Дебелина', 'mm'], ['MEAS4 Екран', 'in'], ['MEAS4 Тегло', 'g'], ['MEAS4 Гръдна обиколка', 'cm'], ['MEAS4 Талия', 'cm'], ['MEAS4 Дължина', 'cm']] as $i => [$n, $u]) { $m = Measure::create($n, $u, 900 + $i); app(App\Services\MeasureWriter::class)->save($m); $ms[$n] = (string) $m->id(); }
$tpl = function ($name, $names, $kind) use ($ms) { $t = MeasurementTemplate::create($name, array_map(fn ($n) => $ms[$n], $names), 900, $kind); app(App\Services\MeasurementTemplateWriter::class)->save($t); return (string) $t->id(); };
$phones = $tpl('MEAS4 Телефони', ['MEAS4 Дебелина', 'MEAS4 Екран', 'MEAS4 Тегло'], MeasurementTemplateKind::SINGLE);
$shirts = $tpl('MEAS4 Ризи', ['MEAS4 Гръдна обиколка', 'MEAS4 Талия', 'MEAS4 Дължина'], MeasurementTemplateKind::BY_SIZE);
$dresses = $tpl('MEAS4 Рокли', ['MEAS4 Талия'], MeasurementTemplateKind::BY_SIZE);
$size = new AttributeDefinition(id: null, code: 'meas4-audit-size', name: 'MEAS4 размер', type: AttributeType::SELECT, isSize: true); app(AttributeDefinitionRepository::class)->save($size);
$colour = new AttributeDefinition(id: null, code: 'meas4-audit-colour', name: 'MEAS4 цвят', type: AttributeType::SELECT); app(AttributeDefinitionRepository::class)->save($colour);
$sizes = []; foreach (['S', 'M', 'L'] as $i => $v) { $x = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: $v, sortOrder: $i); app(AttributeValueRepository::class)->save($x); $sizes[] = $x; }
$colours = []; foreach (['Черно', 'Бяло'] as $i => $v) { $x = new AttributeValue(id: null, attributeDefinitionId: $colour->id(), value: $v, sortOrder: $i); app(AttributeValueRepository::class)->save($x); $colours[] = $x; }
$class = (string) DB::table('shipping_classes')->where('is_default', 1)->value('code');
$shirt = Product::createVariable('MEAS4 Риза', 'MEAS4AUDIT-V', 'meas4-audit-shirt'); $shirt->declareVariationAxes([new VariationAxis($colour, $colours), new VariationAxis($size, $sizes)]);
foreach ($colours as $c) { foreach ($sizes as $s) { $v = $shirt->addStandardVariation([$colour->id() => $c->id(), $size->id() => $s->id()], 'MEAS4AUDIT-V-'.$c->value().'-'.$s->value()); } }
app(ProductRepository::class)->save($shirt);
$phone = Product::createSimple('MEAS4 Телефон', 'MEAS4AUDIT-P', 'meas4-audit-phone'); app(ProductRepository::class)->save($phone);
DB::table('catalog_variations')->whereIn('product_id', [$shirt->id(), $phone->id()])->update(['shipping_class' => $class]);
DB::table('catalog_products')->whereIn('id', [$shirt->id(), $phone->id()])->update(['product_group_id' => DB::table('catalog_product_groups')->orderBy('id')->value('id')]);
echo json_encode(['phone' => (string) $phone->id(), 'shirt' => (string) $shirt->id(), 'phones' => $phones, 'shirts' => $shirts, 'dresses' => $dresses, 'measures' => $ms, 'sizes' => array_map(fn ($x) => (string) $x->id(), $sizes),
  'phoneUrl' => App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $phone->id()]), 'shirtUrl' => App\Filament\Resources\ProductResource::getUrl('edit-variable', ['record' => $shirt->id()])]);`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'MEAS4 Audit', $role));
echo 'ok';`;

const LOG_ON = String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1'); echo 'ok';`;
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } echo 'restored';`;

// the stored numbers of a product: "row|measure=hundredths" lines, rows named by the size value (or «product»)
const CELLS = (slug) => String.raw`
use Illuminate\Support\Facades\DB;
$pid = DB::table('catalog_products')->where('slug', '${slug}')->value('id');
$rows = DB::table('catalog_measurement_values as v')->leftJoin('catalog_attribute_values as a', 'a.id', '=', 'v.size_value_id')->join('catalog_measures as m', 'm.id', '=', 'v.measure_id')->where('v.product_id', $pid)->orderBy('v.id')->get(['a.value as size', 'm.name as measure', 'v.value_hundredths as h']);
echo json_encode($rows->map(fn ($r) => ($r->size ?? 'product').'|'.str_replace('MEAS4 ', '', $r->measure).'='.$r->h)->all());`;
const TEMPLATE_OF = (slug) => String.raw`use Illuminate\Support\Facades\DB; $pid = DB::table('catalog_products')->where('slug', '${slug}')->value('id'); echo (string) DB::table('catalog_product_measurement_templates as p')->join('catalog_measurement_templates as t', 't.id', '=', 'p.template_id')->where('p.product_id', $pid)->value('t.name');`;
const PRODUCT_NAME = (slug) => String.raw`echo (string) Illuminate\Support\Facades\DB::table('catalog_products')->where('slug', '${slug}')->value('name');`;
const AUDIT = (max) => String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('activity_log')->where('id', '>', ${max})->where('entity_type', 'product')->where('field', 'like', 'measurement%')->orderBy('id')->get(['field', 'old_value', 'new_value'])->all());`;

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
    report.world = { phoneUrl: world.phoneUrl, shirtUrl: world.shirtUrl };
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    php(LOG_ON);

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name, full = false) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); };
    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { if (r.status() >= 500) { consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); } });
    };
    const login = async (page) => {
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', EMAIL);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    };
    // the product's «Запази» (the variable page may ask to confirm)
    const save = async (page) => {
        await page.locator('button:visible').filter({ hasText: /^\s*(Запази|Запиши|Save)/ }).first().click();
        await sleep(900);
        const modal = page.locator('.fi-modal-window:visible');
        if (await modal.count()) { await modal.locator('button:visible').filter({ hasText: /^\s*(Запази|Потвърди|Да|Confirm|Save)/ }).last().click(); }
        await sleep(3500);
    };
    const openTab = async (page) => { await page.getByRole('tab', { name: /^\s*Размери\s*$/ }).first().click(); await sleep(600); };
    // the picker (Filament's non-native select): open it, choose by the option text
    const picker = (page) => page.locator('.fi-fo-field', { hasText: 'Шаблон за размери' }).first();
    const choose = async (page, text) => {
        await picker(page).locator('.fi-select-input-btn').first().click(); await sleep(400);
        await page.locator('[role=option]:visible').filter({ hasText: text }).first().click(); await sleep(1800);
    };
    const cell = (page, label) => page.locator(`input[data-meas-cell][aria-label="${label}"]`);
    const focusedLabel = (page) => page.evaluate(() => document.activeElement?.getAttribute('aria-label') ?? '');

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
    ctx.setDefaultTimeout(30000);
    const admin = await ctx.newPage(); watch(admin, 'admin');
    await login(admin);

    // ================= Flow A: the simple product, a «Общи размери» template
    await admin.goto(world.phoneUrl, { waitUntil: 'networkidle' }); await sleep(800);
    await openTab(admin);
    check('the simple product has the tab «Размери»', await admin.getByRole('tab', { name: /^\s*Размери\s*$/ }).count() === 1);
    await picker(admin).locator('.fi-select-input-btn').first().click(); await sleep(400);
    const options = (await admin.locator('[role=option]:visible').allInnerTexts()).join(' | ');
    check('the picker offers «Телефони» (a general template) and NOT «Ризи» (by size) to a simple product', options.includes('MEAS4 Телефони') && ! options.includes('MEAS4 Ризи'), options);
    await admin.keyboard.press('Escape'); await sleep(300);
    await choose(admin, 'MEAS4 Телефони');
    check('the single template is a vertical list, not a table', (await admin.locator('.meas-single').count()) === 1 && (await admin.locator('table.meas-table').count()) === 0);
    check('with no «Копирай първия ред» button', (await admin.getByText('Копирай първия ред във всички').count()) === 0);
    check('the three measures with their units (мм, инч, г)', await admin.locator('.meas-single').innerText().then((t) => /Дебелина/.test(t) && /Екран/.test(t) && /Тегло/.test(t) && /мм/.test(t) && /инч/.test(t) && /(^|\s)г(\s|$)/.test(t)));
    await cell(admin, 'MEAS4 Дебелина').fill('7,8'); await admin.keyboard.press('Enter');
    check('Enter moved to the next field (Екран)', (await focusedLabel(admin)) === 'MEAS4 Екран', await focusedLabel(admin));
    await admin.keyboard.type('6.1'); await admin.keyboard.press('Enter');
    await admin.keyboard.type('172');
    await admin.keyboard.press('Enter'); await sleep(500);
    check('Enter on the last field did not submit the form (nothing stored yet)', JSON.parse(php(CELLS('meas4-audit-phone'))).length === 0);
    await cell(admin, 'MEAS4 Екран').focus(); await sleep(200); await cell(admin, 'MEAS4 Тегло').focus(); await sleep(200);
    check('6.1 is shown as 6,1 after leaving the field', (await cell(admin, 'MEAS4 Екран').inputValue()) === '6,1', await cell(admin, 'MEAS4 Екран').inputValue());
    await shot(admin, 'meas4-1-single-filled-1440');
    await save(admin);
    check('the single row was stored (7,8 mm, 6,1 in, 172 g) under the one product row', JSON.stringify(JSON.parse(php(CELLS('meas4-audit-phone'))).sort()) === JSON.stringify(['product|Дебелина=780', 'product|Екран=610', 'product|Тегло=17200']), php(CELLS('meas4-audit-phone')));
    check('and the template is assigned', phpMaybe(TEMPLATE_OF('meas4-audit-phone')) === 'MEAS4 Телефони');
    await admin.goto(world.phoneUrl, { waitUntil: 'networkidle' }); await sleep(800); await openTab(admin);
    check('after a reload the tab shows 7,8 / 6,1 / 172', [await cell(admin, 'MEAS4 Дебелина').inputValue(), await cell(admin, 'MEAS4 Екран').inputValue(), await cell(admin, 'MEAS4 Тегло').inputValue()].join() === '7,8,6,1,172');

    // ================= Flow B: the variable shirt, a «По размер» template
    await admin.goto(world.shirtUrl, { waitUntil: 'networkidle' }); await sleep(800);
    await openTab(admin);
    await picker(admin).locator('.fi-select-input-btn').first().click(); await sleep(400);
    const shirtOptions = (await admin.locator('[role=option]:visible').allInnerTexts()).join(' | ');
    check('the variable shirt is offered both kinds', shirtOptions.includes('MEAS4 Ризи') && shirtOptions.includes('MEAS4 Телефони'), shirtOptions);
    await admin.keyboard.press('Escape'); await sleep(300);
    await choose(admin, 'MEAS4 Ризи');
    check('a by-size grid: 3 sizes x 3 measures (colours share the numbers)', (await admin.locator('input[data-meas-cell]').count()) === 9);
    check('the header is «Име, мерна единица» and the first column is the axis name', await admin.locator('table.meas-table').innerText().then((t) => /Гръдна обиколка, см/.test(t) && /MEAS4 размер/.test(t)));
    check('no fallback hint: the size attribute is flagged', (await admin.locator('.meas-fallback').count()) === 0);
    // Enter DOWN the first column, Tab ACROSS the first row
    await cell(admin, 'S — MEAS4 Гръдна обиколка').click();
    await admin.keyboard.type('90'); await admin.keyboard.press('Enter');
    check('Enter moves DOWN to the same column in the next row (M)', (await focusedLabel(admin)) === 'M — MEAS4 Гръдна обиколка', await focusedLabel(admin));
    await admin.keyboard.type('95.5'); await admin.keyboard.press('Enter');
    await admin.keyboard.type('101'); await admin.keyboard.press('Enter');
    check('Enter on the last row stays put and does not submit', (await focusedLabel(admin)) === 'L — MEAS4 Гръдна обиколка' && JSON.parse(php(CELLS('meas4-audit-shirt'))).length === 0);
    await admin.keyboard.press('Shift+Enter');
    check('Shift+Enter moves UP', (await focusedLabel(admin)) === 'M — MEAS4 Гръдна обиколка', await focusedLabel(admin));
    await cell(admin, 'S — MEAS4 Талия').click(); await admin.keyboard.type('70'); await admin.keyboard.press('Tab');
    check('Tab moves ACROSS the row (Дължина)', (await focusedLabel(admin)) === 'S — MEAS4 Дължина', await focusedLabel(admin));
    await admin.keyboard.type('60');
    check('95.5 was normalised to 95,5 when leaving its field', (await cell(admin, 'M — MEAS4 Гръдна обиколка').inputValue()) === '95,5', await cell(admin, 'M — MEAS4 Гръдна обиколка').inputValue());

    // a bad value: the error is on THAT cell and NOTHING is saved (not even the product name changed in the same submit)
    const oldName = php(PRODUCT_NAME('meas4-audit-shirt'));
    await admin.getByRole('tab', { name: /^\s*Основни\s*$/ }).first().click(); await sleep(500);
    await admin.locator('input[id="form.name"]').fill('MEAS4 Риза PROMENEN');
    await openTab(admin);
    await cell(admin, 'M — MEAS4 Талия').fill('9,555');
    await save(admin);
    const cellError = await admin.locator('.meas-error').allInnerTexts();
    check('the bad cell shows its translated error', cellError.length === 1 && /Невалидно число/.test(cellError[0]), cellError.join(' | '));
    check('the error is on that cell (aria-invalid)', (await cell(admin, 'M — MEAS4 Талия').getAttribute('aria-invalid')) === 'true');
    check('nothing was saved: no cells, no product rename', JSON.parse(php(CELLS('meas4-audit-shirt'))).length === 0 && php(PRODUCT_NAME('meas4-audit-shirt')) === oldName, php(PRODUCT_NAME('meas4-audit-shirt')));
    await admin.locator('.meas-error').first().scrollIntoViewIfNeeded(); await sleep(300);
    await shot(admin, 'meas4-2-cell-error-1440');

    // fix it, copy the first row (only the EMPTY cells), save
    await cell(admin, 'M — MEAS4 Талия').fill('');
    await admin.getByRole('button', { name: /Копирай първия ред във всички/ }).click(); await sleep(1800);
    check('copy first row: only empty cells were filled (M Талия=70, M Дължина=60, L Талия=70, L Дължина=60); typed numbers stay', [await cell(admin, 'M — MEAS4 Талия').inputValue(), await cell(admin, 'M — MEAS4 Дължина').inputValue(), await cell(admin, 'L — MEAS4 Талия').inputValue(), await cell(admin, 'L — MEAS4 Дължина').inputValue(), await cell(admin, 'M — MEAS4 Гръдна обиколка').inputValue(), await cell(admin, 'L — MEAS4 Гръдна обиколка').inputValue()].join('|') === '70|60|70|60|95,5|101');
    check('the stale cell error is gone after the cell was corrected / copied', (await admin.locator('.meas-error').count()) === 0 && (await cell(admin, 'M — MEAS4 Талия').getAttribute('aria-invalid')) === null);
    check('a notice says how many cells were filled', /Попълнени са 4 клетки/.test(await admin.locator('body').innerText()));
    await shot(admin, 'meas4-3-shirt-filled-1440');
    await save(admin);
    const stored = JSON.parse(php(CELLS('meas4-audit-shirt')));
    report.shirtCells = stored;
    check('9 numbers stored, 95,5 as 9550', stored.length === 9 && stored.includes('M|Гръдна обиколка=9550') && stored.includes('S|Гръдна обиколка=9000'), stored.join());
    check('the product rename of that submit is saved too (the invalid submit was the one refused)', php(PRODUCT_NAME('meas4-audit-shirt')) !== oldName);
    await admin.goto(world.shirtUrl, { waitUntil: 'networkidle' }); await sleep(800); await openTab(admin);
    check('after a reload the numbers are shown bg-formatted (95,5)', (await cell(admin, 'M — MEAS4 Гръдна обиколка').inputValue()) === '95,5' && (await cell(admin, 'S — MEAS4 Гръдна обиколка').inputValue()) === '90');

    // switch template: warning + hidden count; switch back: the numbers are back
    check('no warning before switching', (await admin.getByText('Числата за показатели, които новият шаблон няма').count()) === 0);
    await choose(admin, 'MEAS4 Рокли');
    check('the columns changed at once (only Талия)', (await admin.locator('input[data-meas-cell]').count()) === 3);
    check('the warning under the select is visible', await admin.getByText('Числата за показатели, които новият шаблон няма, не се изтриват').isVisible());
    check('the hidden count line says 6 hidden values', /Има 6 скрити стойности/.test(await admin.locator('.meas-hidden').innerText()));
    await shot(admin, 'meas4-4-switch-warning-1440');
    await choose(admin, 'MEAS4 Ризи');
    check('switching back brings the numbers back', (await admin.locator('input[data-meas-cell]').count()) === 9 && (await cell(admin, 'L — MEAS4 Гръдна обиколка').inputValue()) === '101' && (await admin.locator('.meas-hidden').count()) === 0);
    check('switching alone saved nothing (the template is still «Ризи»)', phpMaybe(TEMPLATE_OF('meas4-audit-shirt')) === 'MEAS4 Ризи');

    // a phone (390): the grid scrolls inside its own box, the first column is sticky, the page does not scroll sideways
    await admin.setViewportSize({ width: 390, height: 900 });
    await admin.goto(world.shirtUrl, { waitUntil: 'networkidle' }); await sleep(800); await openTab(admin);
    await admin.locator('.meas').scrollIntoViewIfNeeded(); await sleep(300);
    const geo = await admin.evaluate(() => {
        const box = document.querySelector('.meas-scroll');
        const first = document.querySelector('.meas-table tbody th');
        const before = first.getBoundingClientRect().left;
        box.scrollLeft = 120;
        const after = first.getBoundingClientRect().left;
        return { boxScroll: box.scrollWidth, boxClient: box.clientWidth, page: document.documentElement.scrollWidth, inner: window.innerWidth, moved: box.scrollLeft, stickyBefore: before, stickyAfter: after, boxLeft: box.getBoundingClientRect().left };
    });
    report.geo390 = geo;
    check('390 px: the grid scrolls inside its own box', geo.boxScroll > geo.boxClient && geo.moved > 0, JSON.stringify(geo));
    check('390 px: the first column stays in place while the box scrolls (sticky)', Math.abs(geo.stickyAfter - geo.stickyBefore) < 1.5, JSON.stringify(geo));
    check('390 px: the page does not scroll sideways', geo.page <= geo.inner + 1, JSON.stringify(geo));
    await shot(admin, 'meas4-5-grid-390');

    // the audit trail of the saves
    const audit = JSON.parse(php(AUDIT(maxLog)));
    report.audit = audit;
    check('audit: one entry per changed cell, readable names, bg format (measurement[M][Гръдна обиколка] 95,5)', audit.some((a) => a.field === 'measurement[M][MEAS4 Гръдна обиколка]' && a.new_value === '95,5' && a.old_value === null), JSON.stringify(audit.slice(0, 4)));
    check('audit: the single row entry names the row «product»', audit.some((a) => a.field === 'measurement[product][MEAS4 Дебелина]' && a.new_value === '7,8'), '');
    check('audit: the template change by name', audit.some((a) => a.field === 'measurement_template' && a.old_value === '—' && a.new_value === 'MEAS4 Телефони'), '');

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
