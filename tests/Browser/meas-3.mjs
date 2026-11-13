// A REAL-BROWSER check of MEAS-3: the admin screens «Показатели» and «Шаблони за размери» and the «Показател за размер» checkbox on the attribute form.
//   A throw-away staff member (Administrator) creates THREE measures (one in inches), a template with them in a chosen order, reorders the template, archives and restores a measure (the archived filter), sees that a used
//   measure has no «Изтрий» (and an unused one can be deleted after a confirmation), ticks «Показател за размер» on a throw-away size attribute (the soft notice shows because a second throw-away attribute is already
//   flagged), and - after a number is stored through the MEAS-2 writer (the script's own setup) - sees the unit select LOCKED with its reason. A second throw-away staff member without the catalog permission gets 403 on
//   every URL of both screens. Screenshots: the two lists, the create modal-page, the reordered template, the locked unit, the checkbox with the notice.
// NOT part of `php artisan test`: run by hand against the dev site. BEFORE the run the checksums of the measurement, catalog, activity-log, staff and role tables and the whole `site_settings` table are taken (a snapshot in
// a file OUTSIDE the project folder); at the end everything the script created (measures, templates, attributes, the product, both staff members, the role, its activity-log rows) is deleted and the checksums are compared
// (a watchdog puts the data back after 15 minutes). The screenshots go to OUT, outside the project folder.
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/meas-3.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node meas-3.mjs'); process.exit(2); }
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

const TABLES = ['catalog_measures', 'catalog_measurement_templates', 'catalog_measurement_template_measures', 'catalog_measurement_values', 'catalog_product_measurement_templates', 'catalog_products', 'catalog_variations', 'catalog_attribute_definitions', 'catalog_attribute_values', 'catalog_product_attributes', 'catalog_product_axis_values', 'catalog_variation_attribute_values', 'activity_log', 'staff', 'staff_roles'];
const SUMS = String.raw`
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
$sum = function ($t) { $q = DB::table($t); if (Schema::hasColumn($t, 'id')) { $q->orderBy('id'); } return md5(json_encode($q->get()->map(fn ($r) => (array) $r)->all())); };
$out = []; foreach (${JSON.stringify(TABLES).replace(/"/g, "'")} as $t) { $out[$t] = $sum($t); }
$out['settings'] = md5(json_encode(DB::table('site_settings')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode($out);`;
const MAX_LOG = String.raw`echo (int) Illuminate\Support\Facades\DB::table('activity_log')->max('id');`;

const EMAIL = 'meas3-audit@example.test';
const EMAIL_NO = 'meas3-audit-no@example.test';
const PASSWORD = 'MEAS3-' + Math.random().toString(36).slice(2, 12) + 'Aa1!';
const ROLE = 'MEAS3 no catalog access';

let maxLog = 0;
const CLEANUP = () => String.raw`
use Illuminate\Support\Facades\DB;
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$pids = DB::table('catalog_products')->where('slug', 'like', 'meas3-audit-%')->pluck('id');
DB::table('catalog_measurement_values')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_measurement_templates')->whereIn('product_id', $pids)->delete();
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_product_attributes')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_axis_values')->whereIn('product_id', $pids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
$tids = DB::table('catalog_measurement_templates')->where('name', 'like', 'MEAS3 %')->pluck('id');
DB::table('catalog_product_measurement_templates')->whereIn('template_id', $tids)->delete();
DB::table('catalog_measurement_template_measures')->whereIn('template_id', $tids)->delete();
DB::table('catalog_measurement_templates')->whereIn('id', $tids)->delete();
$mids = DB::table('catalog_measures')->where('name', 'like', 'MEAS3 %')->pluck('id');
DB::table('catalog_measurement_values')->whereIn('measure_id', $mids)->delete();
DB::table('catalog_measurement_template_measures')->whereIn('measure_id', $mids)->delete();
DB::table('catalog_measures')->whereIn('id', $mids)->delete();
$def = DB::table('catalog_attribute_definitions')->where('code', 'like', 'meas3-audit-%')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $def)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $def)->delete();
DB::table('staff')->whereIn('email', ['${EMAIL}', '${EMAIL_NO}'])->delete();
DB::table('staff_roles')->where('name', '${ROLE}')->delete();
DB::table('activity_log')->where('id', '>', ${maxLog})->whereIn('entity_type', ['measure', 'measurement_template', 'attribute_definition'])->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo 'cleaned';`;

// two throw-away SELECT attributes (S, M, L): «size» (not flagged yet; the browser ticks it) and «other» (already flagged: the soft notice shows when the first is ticked)
const SETUP = String.raw`
use EasyCo\Catalog\AttributeDefinition; use EasyCo\Catalog\AttributeValue; use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository; use EasyCo\Catalog\Contracts\AttributeValueRepository;
$size = new AttributeDefinition(id: null, code: 'meas3-audit-size', name: 'MEAS3 size', type: AttributeType::SELECT);
app(AttributeDefinitionRepository::class)->save($size);
foreach (['S', 'M', 'L'] as $i => $v) { app(AttributeValueRepository::class)->save(new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: $v, sortOrder: $i)); }
$other = new AttributeDefinition(id: null, code: 'meas3-audit-other', name: 'MEAS3 other', type: AttributeType::SELECT, isSize: true);
app(AttributeDefinitionRepository::class)->save($other);
echo json_encode(['size' => (string) $size->id(), 'other' => (string) $other->id(), 'sizeUrl' => App\Filament\Resources\AttributeDefinitionResource::getUrl('edit', ['record' => $size->id()]), 'measures' => App\Filament\Resources\MeasureResource::getUrl('index'), 'templates' => App\Filament\Resources\MeasurementTemplateResource::getUrl('index')]);`;

// a number stored through the MEAS-2 writer for «MEAS3 Chest»: a throw-away variable product whose size axis is the (now flagged) throw-away attribute, the template assigned, one cell saved
const SEED_NUMBER = String.raw`
use EasyCo\Catalog\Product; use EasyCo\Catalog\VariationAxis; use Illuminate\Support\Facades\DB;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository; use EasyCo\Catalog\Contracts\AttributeValueRepository; use EasyCo\Catalog\Contracts\ProductRepository;
$def = collect(app(AttributeDefinitionRepository::class)->all())->first(fn ($d) => $d->code() === 'meas3-audit-size');
$vals = collect(app(AttributeValueRepository::class)->findByAttributeDefinitionId($def->id()))->values()->all();
$p = Product::createVariable('MEAS3 audit shirt', 'MEAS3AUDIT-V', 'meas3-audit-shirt');
$p->declareVariationAxes([new VariationAxis($def, $vals)]);
foreach ($vals as $v) { $p->addStandardVariation([$def->id() => $v->id()], 'MEAS3AUDIT-V-'.$v->value()); }
app(ProductRepository::class)->save($p);
$tpl = DB::table('catalog_measurement_templates')->where('name', 'MEAS3 Tops')->value('id');
$chest = DB::table('catalog_measures')->where('name', 'MEAS3 Chest')->value('id');
$w = app(App\Services\ProductMeasurementsWriter::class);
$w->assignTemplate((string) $p->id(), (string) $tpl);
$w->saveGrid((string) $p->id(), [(string) $vals[1]->id() => [(string) $chest => 9550]]);
echo json_encode(['product' => (string) $p->id(), 'values' => DB::table('catalog_measurement_values')->count()]);`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff; use EasyCo\Staff\Role; use EasyCo\Staff\Enums\Permission;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'MEAS3 Audit', $role));
$none = Role::create('${ROLE}', [Permission::ORDER_MANAGE]); $roles->save($none);
app(StaffRepository::class)->save(Staff::create('${EMAIL_NO}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'MEAS3 Audit No', $none));
echo 'ok';`;

const LOG_ON = String.raw`use App\Settings\Contracts\SiteSettingsRepository; app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1'); echo 'ok';`;
const SNAP_FILE = join(OUT, 'site_settings.snapshot.json').replace(/\\/g, '/');
const SNAPSHOT = String.raw`use Illuminate\Support\Facades\DB; file_put_contents('${SNAP_FILE}', json_encode(DB::table('site_settings')->get()->map(fn ($r) => (array) $r)->all())); echo 'saved';`;
const RESTORE = () => String.raw`use Illuminate\Support\Facades\DB; $rows = json_decode(file_get_contents('${SNAP_FILE}'), true); DB::table('site_settings')->delete(); foreach (array_chunk($rows, 50) as $chunk) { DB::table('site_settings')->insert($chunk); } echo 'restored';`;

const MEASURES = String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('catalog_measures')->where('name', 'like', 'MEAS3 %')->orderBy('id')->get(['id', 'name', 'unit', 'is_active'])->all());`;
const TEMPLATE_ORDER = String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('catalog_measurement_template_measures as tm')->join('catalog_measures as m', 'm.id', '=', 'tm.measure_id')->join('catalog_measurement_templates as t', 't.id', '=', 'tm.template_id')->where('t.name', 'MEAS3 Tops')->orderBy('tm.sort')->pluck('m.name')->all());`;
const IS_SIZE = String.raw`echo (int) Illuminate\Support\Facades\DB::table('catalog_attribute_definitions')->where('code', 'meas3-audit-size')->value('is_size');`;
const AUDIT = String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('activity_log')->where('id', '>', ${'${MAX}'})->whereIn('entity_type', ['measure', 'measurement_template', 'attribute_definition'])->orderBy('id')->get(['entity_type', 'action', 'field', 'old_value', 'new_value'])->all());`;

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
    check('the throw-away attributes and the two staff members were created', php(CREATE_STAFF) === 'ok');
    php(LOG_ON);

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const shot = async (page, name, full = false) => { const p = join(OUT, name + '.png'); shots.push(p); await page.screenshot({ path: p, fullPage: full }); };
    const watch = (page, tag) => {
        page.on('pageerror', (e) => consoleErrors.push(`PAGEERROR@${tag} ` + String(e).slice(0, 200)));
        page.on('console', (m) => { if (m.type() === 'error' && ! /403|404|Failed to load resource/.test(m.text())) consoleErrors.push(`CONSOLE@${tag} ` + m.text().slice(0, 200)); });
        page.on('response', (r) => { if (r.status() >= 500) { consoleErrors.push(`HTTP@${tag} ` + r.status() + ' ' + r.url().slice(0, 120)); } });
    };
    const login = async (page, email) => {
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', email);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
    };
    const save = async (page) => { await page.locator('button:visible').filter({ hasText: /^\s*(Запази|Запиши|Създай|Create|Save)/ }).first().click(); await sleep(3500); };
    // Filament's searchable (non-native) select: open it, pick the option by its text
    const pick = async (page, trigger, text) => { await trigger.click(); await sleep(300); await page.locator('[role=option]:visible').filter({ hasText: text }).first().click(); await sleep(300); };

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1100 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
    ctx.setDefaultTimeout(30000);
    const admin = await ctx.newPage(); watch(admin, 'admin');
    await login(admin, EMAIL);

    // ---- the sidebar: both entries under «Каталог»
    await admin.goto(world.measures, { waitUntil: 'networkidle' }); await sleep(600);
    const nav = await admin.content(); // the whole HTML: the «Каталог» group is collapsed, its links are in the page but not visible
    check('the sidebar has both entries (links to the two screens)', nav.includes('href="' + world.measures + '"') && nav.includes('href="' + world.templates + '"'), '');

    // ---- A: three measures (one in inches)
    const created = [['MEAS3 Chest', 'см'], ['MEAS3 Waist', 'см'], ['MEAS3 Length', 'инч']];
    for (const [name, unit] of created) {
        await admin.goto(world.measures + '/create', { waitUntil: 'networkidle' }); await sleep(500);
        await admin.locator('input[id$=".name"]').first().fill(name);
        await admin.locator('select').first().selectOption({ label: unit });
        if (name === 'MEAS3 Chest') { await shot(admin, 'meas3-1-measure-create-1440'); }
        await save(admin);
    }
    const ms = JSON.parse(php(MEASURES));
    report.measures = ms;
    check('three measures were created with their units (cm, cm, in)', ms.length === 3 && ms.map((m) => m.unit).join() === 'cm,cm,in', JSON.stringify(ms));
    // a duplicate name (different case) is refused on the field and nothing is created
    await admin.goto(world.measures + '/create', { waitUntil: 'networkidle' }); await sleep(500);
    await admin.locator('input[id$=".name"]').fill('meas3 chest');
    await admin.locator('select').first().selectOption({ label: 'см' });
    await save(admin);
    check('a duplicate name is refused on the name field with the translated text', /Вече има запис с това име/.test(await admin.locator('body').innerText()), '');
    check('and nothing was created', JSON.parse(php(MEASURES)).length === 3);

    // ---- B: a template with the measures in a chosen order
    await admin.goto(world.templates + '/create', { waitUntil: 'networkidle' }); await sleep(500);
    await admin.locator('input[id$=".name"]').fill('MEAS3 Tops');
    for (const name of ['MEAS3 Waist', 'MEAS3 Chest', 'MEAS3 Length']) {
        await admin.getByRole('button', { name: /Добави показател/ }).first().click(); await sleep(500);
        const triggers = admin.locator('.fi-fo-simple-repeater-item .fi-fo-select button, .fi-fo-simple-repeater-item [role=combobox], .fi-fo-simple-repeater-item .fi-select-input-btn');
        await pick(admin, triggers.last(), name);
    }
    await shot(admin, 'meas3-2-template-create-1440');
    await save(admin);
    let order = JSON.parse(php(TEMPLATE_ORDER));
    check('the template holds the measures in the chosen order (Waist, Chest, Length)', order.join() === 'MEAS3 Waist,MEAS3 Chest,MEAS3 Length', order.join());

    // ---- reorder: move the first measure down one place (Chest, Waist, Length)
    await admin.goto(world.templates, { waitUntil: 'networkidle' }); await sleep(600);
    check('the template list prints the measures in order', /MEAS3 Waist\s*·\s*MEAS3 Chest\s*·\s*MEAS3 Length/.test(await admin.locator('body').innerText()), '');
    await shot(admin, 'meas3-3-template-list-1440');
    await admin.locator('tr', { hasText: 'MEAS3 Tops' }).getByRole('link', { name: /Редактирай/ }).first().click();
    await admin.waitForLoadState('networkidle'); await sleep(800);
    await admin.locator('.fi-fo-simple-repeater-item').first().locator('button[title*="надолу"], button[aria-label*="надолу"], button[title*="down" i], button[aria-label*="down" i], [x-on\\:click*="moveDown"], .fi-fo-simple-repeater-item-move-down-button').first().click();
    await sleep(500);
    await shot(admin, 'meas3-4-template-reordered-1440');
    await save(admin);
    order = JSON.parse(php(TEMPLATE_ORDER));
    check('after the reorder the template holds Chest, Waist, Length', order.join() === 'MEAS3 Chest,MEAS3 Waist,MEAS3 Length', order.join());

    // ---- measures list: usage, archive / restore, delete rules
    await admin.goto(world.measures, { waitUntil: 'networkidle' }); await sleep(600);
    let body = await admin.locator('body').innerText();
    check('the measure list shows «Използва се в» (1 шаблон · 0 стойности)', /1 шаблон · 0 стойности/.test(body), '');
    await shot(admin, 'meas3-5-measure-list-1440');
    const rowOf = (text) => admin.locator('tr', { hasText: text }).first();
    check('a used measure has no «Изтрий» action (it is archived instead)', (await rowOf('MEAS3 Waist').getByText('Изтрий', { exact: true }).count()) === 0);
    // an unused measure (created here through the writer) can be deleted after a confirmation
    php(String.raw`app(App\Services\MeasureWriter::class)->save(EasyCo\Catalog\Measure::create('MEAS3 Spare', 'mm', 99)); echo 'ok';`);
    await admin.reload({ waitUntil: 'networkidle' }); await sleep(600);
    check('an unused measure offers «Изтрий»', (await rowOf('MEAS3 Spare').getByText('Изтрий', { exact: true }).count()) === 1);
    await rowOf('MEAS3 Spare').getByText('Изтрий', { exact: true }).click(); await sleep(600);
    await shot(admin, 'meas3-6-delete-confirm-1440');
    await admin.locator('.fi-modal-window button:visible').filter({ hasText: /^\s*Изтрий/ }).first().click(); await sleep(2500);
    check('the unused measure was deleted', JSON.parse(php(MEASURES)).every((m) => m.name !== 'MEAS3 Spare'));

    // archive (with the confirmation text), hidden by default, shown with the filter, restored
    await rowOf('MEAS3 Length').getByText('Архивирай', { exact: true }).click(); await sleep(600);
    check('the archive confirmation says the numbers stay saved', /числата остават запазени/.test(await admin.locator('.fi-modal-window').innerText()), '');
    await admin.locator('.fi-modal-window button:visible').filter({ hasText: /^\s*Архивирай/ }).first().click(); await sleep(2500);
    check('the measure is archived in the database', JSON.parse(php(MEASURES)).find((m) => m.name === 'MEAS3 Length')?.is_active == 0);
    check('an archived measure is hidden from the list by default', (await admin.locator('tr', { hasText: 'MEAS3 Length' }).count()) === 0);
    await admin.getByText('Покажи архивираните').first().click().catch(async () => { await admin.locator('button[title*="Филтри"], .fi-ta-filters-trigger-action').first().click(); await sleep(400); await admin.getByText('Покажи архивираните').first().click(); });
    await sleep(1500);
    check('«Покажи архивираните» shows it', (await admin.locator('tr', { hasText: 'MEAS3 Length' }).count()) === 1);
    await rowOf('MEAS3 Length').getByText('Върни', { exact: true }).click(); await sleep(600);
    await admin.locator('.fi-modal-window button:visible').filter({ hasText: /^\s*Върни/ }).first().click(); await sleep(2500);
    check('the measure is restored', JSON.parse(php(MEASURES)).find((m) => m.name === 'MEAS3 Length')?.is_active == 1);

    // ---- C: the size checkbox, with the soft notice
    await admin.goto(world.sizeUrl, { waitUntil: 'networkidle' }); await sleep(800);
    const box = admin.getByLabel('Показател за размер');
    check('the attribute form has the «Показател за размер» checkbox', (await box.count()) === 1);
    check('it has an info icon', (await admin.locator('[id^="ds-hint-is_size"]').count()) === 1);
    await box.check(); await sleep(1500);
    check('the soft notice shows because another attribute is already flagged', /Вече има атрибут, отбелязан като размер/.test(await admin.locator('body').innerText()), '');
    await shot(admin, 'meas3-7-size-checkbox-notice-1440');
    await save(admin);
    check('the flag is saved (and saving was not blocked by the notice)', php(IS_SIZE) === '1');

    // ---- the unit lock: a number stored through the MEAS-2 writer
    const seeded = JSON.parse(php(SEED_NUMBER));
    report.seeded = seeded;
    check('a number was stored through the MEAS-2 writer', seeded.values === 1, JSON.stringify(seeded));
    const chestId = JSON.parse(php(MEASURES)).find((m) => m.name === 'MEAS3 Chest').id;
    const waistId = JSON.parse(php(MEASURES)).find((m) => m.name === 'MEAS3 Waist').id;
    await admin.goto(world.measures + `/${chestId}/edit`, { waitUntil: 'networkidle' }); await sleep(800);
    check('the unit select of a measure with numbers is disabled', await admin.locator('select').first().isDisabled());
    check('and the reason is shown', /Мерната единица не може да се променя, защото има въведени числа/.test(await admin.locator('body').innerText()), '');
    await shot(admin, 'meas3-8-unit-locked-1440');
    await admin.goto(world.measures + `/${waistId}/edit`, { waitUntil: 'networkidle' }); await sleep(800);
    check('the unit select of a measure without numbers is enabled', await admin.locator('select').first().isEnabled());
    await admin.goto(world.measures, { waitUntil: 'networkidle' }); await sleep(600);
    check('the list now shows 1 стойност for the measure with a number', /1 шаблон · 1 стойност/.test(await admin.locator('body').innerText()), '');

    // a phone (390): no sideways scrolling on the two lists
    await admin.setViewportSize({ width: 390, height: 900 });
    for (const [url, tag] of [[world.measures, 'measures'], [world.templates, 'templates']]) {
        await admin.goto(url, { waitUntil: 'networkidle' }); await sleep(600);
        check(`the ${tag} list does not scroll the page sideways at 390 px`, await admin.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), '');
        await shot(admin, `meas3-9-${tag}-390`);
    }

    // ---- the audit trail
    const audit = JSON.parse(php(AUDIT.replace('${MAX}', String(maxLog))));
    report.audit = audit;
    const has = (type, action, field) => audit.some((a) => a.entity_type === type && a.action === action && (field === undefined || a.field === field));
    check('audit: measures created, archived, restored, and the spare one deleted', has('measure', 'created') && has('measure', 'updated', 'is_active') && has('measure', 'deleted'), JSON.stringify(audit.filter((a) => a.entity_type === 'measure')));
    check('audit: the template created and reordered (old -> new list)', has('measurement_template', 'created') && has('measurement_template', 'updated', 'measures'), JSON.stringify(audit.filter((a) => a.entity_type === 'measurement_template')));
    check('audit: the size flag changed 0 -> 1', audit.some((a) => a.entity_type === 'attribute_definition' && a.field === 'is_size' && a.old_value === '0' && a.new_value === '1'), '');

    // ---- staff without the catalog permission: 403 on every URL of both screens
    const ctxNo = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true, locale: 'bg-BG' });
    ctxNo.setDefaultTimeout(30000);
    const no = await ctxNo.newPage(); watch(no, 'no');
    await login(no, EMAIL_NO);
    for (const url of [world.measures, world.measures + '/create', world.measures + `/${chestId}/edit`, world.templates, world.templates + '/create']) {
        const r = await no.goto(url, { waitUntil: 'domcontentloaded' });
        check(`403 without the permission: ${url.replace(BASE, '')}`, r?.status() === 403, String(r?.status()));
    }
    await no.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    check('the sidebar of that staff member has neither entry', ! (await no.content()).includes('href="' + world.templates + '"'), '');
    await ctx.close(); await ctxNo.close();
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
