// A REAL-BROWSER check of P4c: the section sources «Мета информация» and «HTML блокове» (blocks chosen per brand / tag), the brand condition, and the per-product switch «Показвай на този продукт».
//   Real clicks in «Дизайн» → «Секции на продукта» (a blocks section, a meta section, a product-text section), the accordion and a tabs slot, then the product form's «Информация» tab (a switch off for ONE product), then the product
//   pages at 1440 and 390: each product sees only ITS brand's size table, the meta section links work, the switched-off section is gone on one product and stays on the other, and returns when switched on.
// NOT part of `php artisan test`: run by hand against the dev site. It creates ONE throw-away staff member, 1 category, 2 brands, 3 tags, a season, 3 products and 4 HTML blocks («P3 audit …»). Before the run every site_settings
// row is backed up; at the end they are put back BY ID, everything created is deleted (the products' section rows go with them) and the checksums are compared with the ones taken before. A watchdog puts the data back after 12 minutes.
// The screenshots go to OUT, which must be OUTSIDE the project folder (the script refuses otherwise).
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] OUT=<folder outside the project> node tests/Browser/p4c.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node p4c.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const EMAIL = `p4c-${Date.now()}@example.test`;
const PASSWORD = 'P4c-' + Math.random().toString(36).slice(2) + 'Aa1!';

const SUMS = String.raw`
use Illuminate\Support\Facades\DB;
$sum = fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
echo json_encode(['settings' => $sum('site_settings'), 'staff' => DB::table('staff')->count(), 'texts' => $sum('product_section_texts'), 'products' => DB::table('catalog_products')->count(), 'variations' => DB::table('catalog_variations')->count(), 'brands' => $sum('catalog_brands'), 'seasons' => $sum('catalog_seasons'), 'tags' => $sum('catalog_tags'), 'cats' => $sum('catalog_categories'), 'blocks' => $sum('html_blocks')]);`;

const BACKUP = String.raw`
use Illuminate\Support\Facades\DB;
file_put_contents(sys_get_temp_dir().'/p4c_backup.json', json_encode(['settings' => DB::table('site_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'texts' => DB::table('product_section_texts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()]));
echo 'ok';`;

const CLEANUP = String.raw`
use Illuminate\Support\Facades\DB;
$pids = DB::table('catalog_products')->where('slug', 'like', 'p3-audit-%')->pluck('id');
$vids = DB::table('catalog_variations')->whereIn('product_id', $pids)->pluck('id');
DB::table('stock_levels')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variation_attribute_values')->whereIn('variation_id', $vids)->delete();
DB::table('catalog_variations')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_tags')->whereIn('product_id', $pids)->delete();
DB::table('catalog_product_categories')->whereIn('product_id', $pids)->delete();
DB::table('product_section_texts')->whereIn('product_id', $pids)->delete();
DB::table('catalog_products')->whereIn('id', $pids)->delete();
DB::table('catalog_brands')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('catalog_seasons')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('catalog_tags')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('catalog_categories')->where('slug', 'like', 'p3-audit-%')->delete();
DB::table('html_blocks')->where('title', 'like', 'P3 audit%')->delete();
$defs = DB::table('catalog_attribute_definitions')->where('code', 'p3-colour')->pluck('id');
DB::table('catalog_attribute_values')->whereIn('attribute_definition_id', $defs)->delete();
DB::table('catalog_attribute_definitions')->whereIn('id', $defs)->delete();
echo 'cleaned';`;

const RESTORE = String.raw`
use Illuminate\Support\Facades\DB;
$all = json_decode(file_get_contents(sys_get_temp_dir().'/p4c_backup.json'), true);
$bak = $all['settings'];
$put = function () use ($bak) { DB::table('site_settings')->whereNotIn('id', array_column($bak, 'id'))->delete(); foreach ($bak as $row) { DB::table('site_settings')->where('id', $row['id'])->update(['key' => $row['key'], 'value' => $row['value'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]); if (! DB::table('site_settings')->where('id', $row['id'])->exists()) { DB::table('site_settings')->insert($row); } } };
$put();
DB::table('staff')->where('email', '${EMAIL}')->delete();
app(App\Storefront\Header\AppearanceRecompiler::class)->run(app(App\Settings\Contracts\SiteSettingsRepository::class));
$put();
DB::table('product_section_texts')->whereNotIn('id', array_column($all['texts'], 'id'))->delete();
app(App\Storefront\Appearance\StorefrontAppearance::class)->refresh();
echo 'restored';`;

const CREATE_STAFF = String.raw`
use EasyCo\Staff\Contracts\PasswordHasher; use EasyCo\Staff\Contracts\RoleRepository; use EasyCo\Staff\Contracts\StaffRepository; use EasyCo\Staff\Seeders\StaffSystemRolesSeeder; use EasyCo\Staff\Staff;
$roles = app(RoleRepository::class);
$role = $roles->findSystemRoleByName('Administrator');
if ($role === null) { app(StaffSystemRolesSeeder::class)->run($roles); $role = $roles->findSystemRoleByName('Administrator'); }
app(StaffRepository::class)->save(Staff::create('${EMAIL}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'P4c Audit', $role));
echo 'ok';`;

// category, brands Nike (n1) and Guess (n2), tags «дрехи Nike», «обувки Nike», «Лято», a season, 3 variable products (1 Nike clothes + season + 2 tags, 2 Nike shoes, 3 Guess), 4 blocks with conditions
const SETUP = String.raw`
use Illuminate\Support\Facades\DB;
$now = now();
$cat = DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'P3 audit корен', 'slug' => 'p3-audit-root', 'created_at' => $now, 'updated_at' => $now]);
$nike = DB::table('catalog_brands')->insertGetId(['name' => 'P3 Nike', 'slug' => 'p3-audit-nike', 'created_at' => $now, 'updated_at' => $now]);
$guess = DB::table('catalog_brands')->insertGetId(['name' => 'P3 Guess', 'slug' => 'p3-audit-guess', 'created_at' => $now, 'updated_at' => $now]);
$tClothes = DB::table('catalog_tags')->insertGetId(['name' => 'P3 дрехи Nike', 'slug' => 'p3-audit-drehi-nike', 'created_at' => $now, 'updated_at' => $now]);
$tShoes = DB::table('catalog_tags')->insertGetId(['name' => 'P3 обувки Nike', 'slug' => 'p3-audit-obuvki-nike', 'created_at' => $now, 'updated_at' => $now]);
$tSummer = DB::table('catalog_tags')->insertGetId(['name' => 'P3 Лято', 'slug' => 'p3-audit-lyato', 'created_at' => $now, 'updated_at' => $now]);
$season = DB::table('catalog_seasons')->insertGetId(['name' => 'P3 Сезон', 'slug' => 'p3-audit-season', 'created_at' => $now, 'updated_at' => $now]);
$def = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'p3-colour', 'name' => 'P3 цвят', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => $now, 'updated_at' => $now]);
$val = DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $def, 'value' => 'Червен', 'sort_order' => 0, 'colour' => null, 'created_at' => $now, 'updated_at' => $now]);
$spec = [1 => [$nike, [$tClothes, $tSummer], $season], 2 => [$nike, [$tShoes], null], 3 => [$guess, [], null]];
foreach ($spec as $n => [$brand, $tags, $seasonId]) {
  $pid = DB::table('catalog_products')->insertGetId(['type' => 'variable', 'name' => 'P3 audit продукт '.$n, 'slug' => 'p3-audit-'.$n, 'base_sku' => 'P3AUDIT-'.$n, 'status' => 'active', 'catalog_visibility' => 'visible', 'brand_id' => $brand, 'season_id' => $seasonId, 'created_at' => $now, 'updated_at' => $now, 'timeline_at' => now()->addDays(3)->subMinutes($n)]);
  $vid = DB::table('catalog_variations')->insertGetId(['product_id' => $pid, 'sort_order' => 0, 'type' => 'standard', 'status' => 'active', 'attribute_signature' => hash('sha256', "p4c-$n"), 'sku' => "P3AUDIT-V$n", 'is_visible' => 1, 'is_purchasable' => 1, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_variation_attribute_values')->insert(['variation_id' => $vid, 'attribute_definition_id' => $def, 'attribute_value_id' => $val, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('stock_levels')->insert(['variation_id' => $vid, 'quantity' => 5, 'created_at' => $now, 'updated_at' => $now]);
  DB::table('catalog_product_categories')->insert(['product_id' => $pid, 'category_id' => $cat]);
  foreach ($tags as $t) { DB::table('catalog_product_tags')->insert(['product_id' => $pid, 'tag_id' => $t]); }
}
$block = fn ($title, $html, $cond) => DB::table('html_blocks')->insertGetId(['title' => $title, 'body' => $html, 'conditions' => json_encode($cond), 'created_at' => $now, 'updated_at' => $now]);
$b1 = $block('P3 audit размери Nike облекло', '<p>P4CSIZESNIKECLOTHES</p>', ['brands' => [$nike], 'tags' => [$tClothes]]);
$b2 = $block('P3 audit размери Nike обувки', '<p>P4CSIZESNIKESHOES</p>', ['brands' => [$nike], 'tags' => [$tShoes]]);
$b3 = $block('P3 audit размери Guess', '<p>P4CSIZESGUESS</p>', ['brands' => [$guess]]);
$b4 = $block('P3 audit обща бележка', '<p>P4CNOTEFORALL</p>', null);
echo json_encode(['cat' => $cat]);`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

const steps = []; const consoleErrors = []; const shots = []; const report = {};
const check = (name, ok, detail = '') => { process.env.TRACE && console.error((ok ? '· ' : 'FAIL ') + name.slice(0, 90)); steps.push({ name, ok: !! ok, detail: String(detail).slice(0, 700) }); if (! ok) console.error('FAIL ' + name + ' ' + String(detail).slice(0, 300)); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const setting = (key) => { const v = php(String.raw`echo app(App\Settings\Contracts\SiteSettingsRepository::class)->get('${key}') ?? 'null';`); return v === 'null' ? null : JSON.parse(v); };

const look = (page) => page.evaluate(() => {
    const details = [...document.querySelectorAll('.sf-psec')];
    return {
        accordion: details.filter((d) => d.querySelector('summary')?.textContent.trim().startsWith('P4c')).map((d) => ({ title: d.querySelector('summary')?.textContent.trim(), body: d.querySelector('.sf-psec-body')?.textContent.trim() })),
        tabs: [...document.querySelectorAll('.sf-ptab-label')].map((l) => l.textContent.trim()).filter((t) => t.startsWith('P4c')),
        panels: [...document.querySelectorAll('.sf-ptab-panel')].map((p) => p.textContent.trim()),
        metaLinks: [...document.querySelectorAll('.sf-psec-meta a')].map((a) => [a.textContent.trim(), a.getAttribute('href')]),
        hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        text: document.body.innerText,
    };
});

let before = null; let created = false; let watchdog = null;

try {
    before = JSON.parse(php(SUMS));
    php(BACKUP);
    created = true;
    watchdog = setTimeout(() => { try { php(RESTORE); php(CLEANUP); } catch (e) { console.error('WATCHDOG restore failed ' + e); } console.error('WATCHDOG: the run took more than 12 minutes; the data was put back'); process.exit(3); }, 12 * 60 * 1000);
    check('the throw-away staff member was created', php(CREATE_STAFF) === 'ok');
    check('the throw-away category, brands, tags, season, products and blocks were created', JSON.parse(php(SETUP)).cat > 0);
    check('the column `hidden` exists (the migration was run)', php(String.raw`echo Illuminate\Support\Facades\Schema::hasColumn('product_section_texts', 'hidden') ? 'yes' : 'no';`) === 'yes');
    // the throw-away products are raw rows: give product 1 what the product form requires to save (a product group and a shipping class)
    php(String.raw`use Illuminate\Support\Facades\DB; $id = DB::table('catalog_products')->where('slug', 'p3-audit-1')->value('id'); DB::table('catalog_products')->where('id', $id)->update(['product_group_id' => DB::table('catalog_product_groups')->value('id')]); DB::table('catalog_variations')->where('product_id', $id)->update(['shipping_class' => DB::table('catalog_variations')->whereNotNull('shipping_class')->value('shipping_class')]); echo 'ok';`);
    const productId = (n) => Number(php(String.raw`echo Illuminate\Support\Facades\DB::table('catalog_products')->where('slug', 'p3-audit-${n}')->value('id');`));
    const p = (n) => BASE + '/product/p3-audit-' + n;

    // ---- the admin
    const ctxAdmin = await browser.newContext({ viewport: { width: 1440, height: 1300 }, ignoreHTTPSErrors: true });
    ctxAdmin.setDefaultTimeout(25000);
    const admin = await ctxAdmin.newPage();
    admin.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
    admin.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
    await admin.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await admin.fill('input[type=email]', EMAIL);
    await admin.fill('input[type=password]', PASSWORD);
    await admin.keyboard.press('Enter');
    await admin.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });

    const openZone = async (zone) => {
        await admin.goto(BASE + '/admin/design?zone=' + zone, { waitUntil: 'networkidle' });
        await admin.waitForSelector('.ds-props, .ds-palette, .ds-chip-add', { timeout: 30000 });
        await sleep(1500);
    };
    const props = admin.locator('.ds-props');
    const fieldIn = (root, label) => root.locator('.fi-fo-field:visible').filter({ has: admin.locator('.fi-fo-field-label-content:text-is("' + label + '")') }).first();
    const choose = async (root, label, optionRegex) => {
        await fieldIn(root, label).locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
        await sleep(500);
        await admin.locator('[role=option]:visible').filter({ hasText: optionRegex }).first().click();
        await sleep(700);
    };
    const apply = async () => { await admin.locator('button.ds-btn-primary', { hasText: /Приложи|Apply/ }).click(); await sleep(2500); };
    const publish = async () => {
        await admin.locator('.fi-header-actions-ctn .fi-btn', { hasText: 'Публикувай' }).first().click();
        await sleep(900);
        if (await admin.locator('.fi-modal-window:visible').count() > 0) { await admin.locator('.fi-modal-window:visible').last().getByRole('button').filter({ hasNotText: /Отказ|Cancel/ }).last().click(); }
        await sleep(3000);
    };

    // ---- 1. «Секции на продукта»: a blocks section, a meta section, a product-text section
    await openZone('sections');
    // the sections only (not the rows of a section's own block list)
    const items = () => props.locator('.fi-fo-repeater-item:not(.fi-fo-repeater-item .fi-fo-repeater-item)');
    const addSection = async (title, sourceRegex) => {
        await props.getByRole('button', { name: 'Добави секция' }).first().click();
        await sleep(1200);
        const item = items().last();
        await fieldIn(item, 'Заглавие').locator('input').fill(title);
        await sleep(400);
        await choose(item, 'Източник на текста', sourceRegex);

        return item;
    };
    const sizes = await addSection('P4c Таблица с размери', /^HTML блокове$/);
    for (const blockTitle of ['обща бележка', 'размери Nike облекло', 'размери Nike обувки', 'размери Guess']) {
        await sizes.getByRole('button', { name: 'Добави блок' }).click();
        await sleep(1500);
        process.env.TRACE && console.error('inner items', await sizes.locator('.fi-fo-repeater-item').count(), (await sizes.innerText()).replace(/\s+/g, ' ').slice(0, 300));
        await sizes.locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').last().click();
        await sleep(500);
        await admin.locator('[role=option]:visible').filter({ hasText: blockTitle }).first().click();
        await sleep(700);
    }
    const metaItem = await addSection('P4c Мета информация', /^Мета информация$/);
    check('«Мета информация»: the two switches «Покажи сезоните» and «Покажи етикетите» are there, both ON', (await metaItem.locator('button[role=switch][aria-checked=true]:visible').count()) >= 2 && (await metaItem.innerText()).includes('Покажи сезоните') && (await metaItem.innerText()).includes('Покажи етикетите'), (await metaItem.innerText()).slice(0, 300));
    await addSection('P4c Индивидуални размери', /^Текст на продукта$/);
    const geometry = await props.evaluate((el) => { const fields = [...el.querySelectorAll('.fi-fo-field')].filter((f) => f.getClientRects().length > 0).slice(0, 14).map((f) => { const b = f.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top)]; }); return { fields, hscroll: el.scrollWidth > el.clientWidth + 1 }; });
    check('the sections panel is one field per row and does not scroll sideways', geometry.fields.every((f, i, a) => i === 0 || f[1] > a[i - 1][1]) && ! geometry.hscroll, JSON.stringify(geometry));
    const f0 = join(OUT, 'p4c-zone-sections-1440.png'); shots.push(f0); await props.screenshot({ path: f0 });
    await apply();
    await publish();
    const mine = (setting('design.sections.published')?.sections ?? []).filter((s) => s.title.startsWith('P4c'));
    check('publish wrote the three sections: blocks (4, in the chosen order), meta (both on), product text', mine.length === 3 && mine[0].source === 'blocks' && mine[0].blocks.length === 4 && mine[1].source === 'meta' && mine[1].meta?.seasons === true && mine[2].source === 'product', JSON.stringify(mine));

    // ---- 2. the accordion and the tabs slot
    await openZone('summary');
    // (the owner's right column may hold the accordion already: then it is used as it is)
    if (await admin.locator('button.ds-chip-add', { hasText: 'Акордеон' }).count() > 0) {
        await admin.locator('button.ds-chip-add', { hasText: 'Акордеон' }).first().click();
        await sleep(2000);
        await publish();
    }
    await openZone('below');
    await props.getByRole('button', { name: 'Добави слот' }).click();
    await sleep(1200);
    const slot = items().last();
    await choose(slot, 'Вид', /^Табове$/);
    await fieldIn(slot, 'Секции в табовете').locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click();
    await sleep(500);
    await admin.locator('[role=option]:visible').filter({ hasText: /P4c Таблица с размери/ }).first().click();
    await sleep(500);
    await fieldIn(slot, 'Секции в табовете').locator('.fi-select-input-btn, button[role=combobox], [role=combobox]').first().click().catch(() => {});
    await sleep(400);
    await admin.locator('[role=option]:visible').filter({ hasText: /P4c Мета информация/ }).first().click().catch(() => {});
    await sleep(600);
    await admin.keyboard.press('Escape');
    await apply();
    await publish();
    const below = setting('design.below.published');
    check('the tabs slot was published with the two sections', below?.slots?.some((sl) => sl.type === 'tabs' && sl.sections?.length === 2), JSON.stringify(below));

    // ---- 3. the storefront: each product sees its own size table
    const storefront = async (width, mobile, fn) => {
        const c = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, ...(mobile ? { hasTouch: true, isMobile: true } : {}) });
        c.setDefaultTimeout(25000);
        const page = await c.newPage();
        page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 200)));
        page.on('response', (r) => { r.status() >= 500 && consoleErrors.push('HTTP ' + r.status() + ' ' + r.url().slice(0, 120)); });
        await fn(page, `${width}px`);
        await c.close();
    };
    for (const [width, mobile] of [[1440, false], [390, true]]) {
        await storefront(width, mobile, async (page, tag) => {
            const seen = {};
            for (const n of [1, 2, 3]) {
                await page.goto(p(n), { waitUntil: 'networkidle' });
                seen[n] = await look(page);
            }
            report[`pages_${width}`] = Object.fromEntries(Object.entries(seen).map(([n, l]) => [n, { accordion: l.accordion.map((a) => a.title), tabs: l.tabs, meta: l.metaLinks }]));
            const has = (n, s) => seen[n].text.includes(s);
            check(`${tag}: Nike clothes sees the clothes table and the general note, not the shoes or Guess table`, has(1, 'P4CSIZESNIKECLOTHES') && has(1, 'P4CNOTEFORALL') && ! has(1, 'P4CSIZESNIKESHOES') && ! has(1, 'P4CSIZESGUESS'), seen[1].accordion.map((a) => a.body.slice(0, 60)).join(' | '));
            check(`${tag}: Nike shoes sees only the shoes table (the clothes tag is not on it)`, has(2, 'P4CSIZESNIKESHOES') && ! has(2, 'P4CSIZESNIKECLOTHES') && ! has(2, 'P4CSIZESGUESS'));
            check(`${tag}: Guess sees only its own table, and the order of the blocks is the chosen one (note first)`, has(3, 'P4CSIZESGUESS') && ! has(3, 'P4CSIZESNIKECLOTHES') && seen[3].accordion[0].body.indexOf('P4CNOTEFORALL') < seen[3].accordion[0].body.indexOf('P4CSIZESGUESS'));
            check(`${tag}: the accordion shows «Таблица с размери» and the meta section where there is something to show; «Индивидуални размери» (no text) is not printed`, seen[1].accordion.map((a) => a.title).join('|') === 'P4c Таблица с размери|P4c Мета информация' && seen[3].accordion.map((a) => a.title).join('|') === 'P4c Таблица с размери', JSON.stringify([seen[1].accordion.map((a) => a.title), seen[3].accordion.map((a) => a.title)]));
            check(`${tag}: the meta section: the season and both tags are links; a product with none has no meta section or tab`, seen[1].metaLinks.length >= 3 && seen[1].metaLinks.every(([, href]) => /^\/(season|product-tag)\//.test(href)) && ! seen[3].text.includes('P4c Мета информация') && seen[3].tabs.join('|') === 'P4c Таблица с размери', JSON.stringify([seen[1].metaLinks, seen[3].tabs]));
            check(`${tag}: no horizontal scroll`, ! seen[1].hscroll && ! seen[2].hscroll && ! seen[3].hscroll);
            // a meta link works
            await page.goto(p(1), { waitUntil: 'networkidle' });
            const href = await page.locator('.sf-psec-meta a').first().getAttribute('href');
            const status = (await page.request.get(BASE + href)).status();
            check(`${tag}: a meta link opens its page (200)`, status === 200, href + ' ' + status);
            await page.locator('.sf-psec > summary').first().click(); await sleep(300);
            await page.locator('.sf-psec-list').scrollIntoViewIfNeeded(); await sleep(300);
            const shot = join(OUT, `p4c-accordion-${width}.png`); shots.push(shot); await page.screenshot({ path: shot });
            const tabsEl = page.locator('.sf-ptabs').last();
            await tabsEl.scrollIntoViewIfNeeded(); await sleep(300);
            const shotT = join(OUT, `p4c-tabs-${width}.png`); shots.push(shotT); await page.screenshot({ path: shotT });
        });
    }

    // ---- 4. the product form: the switch «Показвай на този продукт» for ONE product
    const id1 = productId(1);
    await admin.goto(BASE + '/admin/products/' + id1 + '/edit-variable', { waitUntil: 'networkidle' });
    await sleep(1500);
    await admin.getByRole('tab', { name: 'Информация' }).first().click();
    await sleep(800);
    const fieldsets = (await admin.locator('fieldset:visible legend').allInnerTexts()).map((s) => s.trim()).filter((s) => /P4c/.test(s));
    check('the «Информация» tab lists EVERY section (a switch each), in order', JSON.stringify(fieldsets) === JSON.stringify(['P4c Таблица с размери', 'P4c Мета информация', 'P4c Индивидуални размери']), JSON.stringify(fieldsets));
    const box = (title) => admin.locator('fieldset', { has: admin.locator('legend', { hasText: title }) }).first();
    check('every switch is ON by default and has the explaining text', (await admin.locator('button[role=switch][aria-checked=true]:visible').count()) >= 3, '');
    check('only the product-text section has a rich-text field', (await box('P4c Таблица с размери').locator('.tiptap, [contenteditable=true]').count()) === 0 && (await box('P4c Индивидуални размери').locator('.tiptap, [contenteditable=true]').count()) > 0);
    // text for «Индивидуални размери», then the meta section OFF for this product
    const editor = box('P4c Индивидуални размери').locator('.tiptap, [contenteditable=true]').first();
    await editor.click(); await admin.keyboard.type('P4CINDIVIDUALSIZES', { delay: 15 }); await sleep(500); await editor.evaluate((e) => e.blur()); await sleep(1500);
    await box('P4c Мета информация').locator('button[role=switch]').first().click();
    await sleep(600);
    const f1 = join(OUT, 'p4c-product-info-tab-1440.png'); shots.push(f1); await admin.screenshot({ path: f1 });
    const save = async () => { await admin.locator('button:visible, a.fi-btn:visible').filter({ hasText: /^\s*(Запази|Запиши|Save)/ }).first().click(); await sleep(4000); };
    await save();
    const rows = JSON.parse(php(String.raw`echo json_encode(Illuminate\Support\Facades\DB::table('product_section_texts')->where('product_id', ${id1})->get(['body', 'hidden'])->map(fn ($r) => [strip_tags($r->body), (bool) $r->hidden])->all());`));
    check('saving stored a row for the text and a row for the switched-off meta section (hidden, no text)', rows.length === 2 && rows.some((r) => r[0].includes('P4CINDIVIDUALSIZES') && r[1] === false) && rows.some((r) => r[0] === '' && r[1] === true), JSON.stringify(rows));

    await storefront(1440, false, async (page, tag) => {
        await page.goto(p(1), { waitUntil: 'networkidle' });
        const one = await look(page);
        check(`${tag}: product 1: the meta section is GONE (accordion and tab), the individual sizes appear, the sizes table stays`, ! one.text.includes('P4c Мета информация') && one.accordion.some((a) => a.title === 'P4c Индивидуални размери' && a.body.includes('P4CINDIVIDUALSIZES')) && one.text.includes('P4CSIZESNIKECLOTHES') && one.tabs.join('|') === 'P4c Таблица с размери', JSON.stringify([one.accordion.map((a) => a.title), one.tabs]));
        await page.goto(p(2), { waitUntil: 'networkidle' });
        const two = await look(page);
        check(`${tag}: product 2 still has its meta section (the switch is per product)`, two.accordion.some((a) => a.title === 'P4c Мета информация'), JSON.stringify(two.accordion.map((a) => a.title)));
    });

    // switch it back ON: the section returns, the text is still there
    await admin.goto(BASE + '/admin/products/' + id1 + '/edit-variable', { waitUntil: 'networkidle' });
    await sleep(1500);
    await admin.getByRole('tab', { name: 'Информация' }).first().click();
    await sleep(800);
    check('the saved switch is OFF when the form is opened again, and the text is loaded', (await box('P4c Мета информация').locator('button[role=switch][aria-checked=false]').count()) === 1 && (await box('P4c Индивидуални размери').innerText()).includes('P4CINDIVIDUALSIZES'));
    await box('P4c Мета информация').locator('button[role=switch]').first().click();
    await sleep(600);
    await save();
    check('switched back ON: the row of the section is gone, the text row stays', JSON.parse(php(String.raw`echo Illuminate\Support\Facades\DB::table('product_section_texts')->where('product_id', ${id1})->count();`)) === 1);
    await storefront(1440, false, async (page, tag) => {
        await page.goto(p(1), { waitUntil: 'networkidle' });
        const one = await look(page);
        check(`${tag}: product 1: the meta section is back`, one.accordion.some((a) => a.title === 'P4c Мета информация'), JSON.stringify(one.accordion.map((a) => a.title)));
    });

    check('no page error and no 5xx during the run', consoleErrors.length === 0, JSON.stringify(consoleErrors.slice(0, 5)));
    await ctxAdmin.close();
} catch (e) {
    check('the run finished without an exception', false, String(e).slice(0, 900));
} finally {
    if (watchdog) clearTimeout(watchdog);
    if (created) {
        try {
            php(CLEANUP);
            php(RESTORE);
            const after = JSON.parse(php(SUMS));
            for (const k of ['settings', 'staff', 'texts', 'products', 'variations', 'brands', 'seasons', 'tags', 'cats', 'blocks']) {
                check(`${k}: back EXACTLY as before the run`, after[k] === before[k], `${before[k]} vs ${after[k]}`);
            }
        } catch (e) {
            check('the data was put back', false, String(e).slice(0, 600));
        }
    }
    await browser.close();
    const failed = steps.filter((s) => ! s.ok);
    console.log(JSON.stringify({ base: BASE, report, shots, console_errors: consoleErrors, steps, failed: failed.length }, null, 2));
    process.exit(failed.length === 0 ? 0 : 1);
}
