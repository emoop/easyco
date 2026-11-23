// A REAL-BROWSER check of ACC-4e-2: the «Забрави» dialog, «Поверителност → Дневник», the «Табло» tile and «Поверителност: настройки», at 1280px and 360px (admin-ui-standard.md rule 7).
//   Screenshots are kept OUTSIDE the project. Data written to the DEV database: ONE throw-away Administrator, one customer (account, address, two orders one of them in progress, a confirmed erase request),
//   two forget-log rows, and the setting `privacy.manual_steps` (two steps; the old value is restored at the end). NOTHING is erased and NO mail is sent: the dialog is opened and closed, never submitted.
//   At the end the script deletes what it made and prints the row counts before and after.
// NOT part of `php artisan test`: run by hand against a dev site that has the ACC-4e-2 code.
//
//   CHROMIUM=<chrome.exe> AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/acc-4e2.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node acc-4e2.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 120000 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const TAG = `acc4e2-${Date.now()}`;
const STAFF = `${TAG}-admin@example.test`;
const PASSWORD = 'Acc4e2-' + Math.random().toString(36).slice(2) + 'Aa1!';
const PERSON = `${TAG}-person@example.test`;
const ERASED1 = `${TAG}-erased1@example.test`;
const ERASED2 = `${TAG}-erased2@example.test`;

const COUNTS = `use Illuminate\\Support\\Facades\\DB; echo json_encode(['orders' => DB::table('orders')->count(), 'accounts' => DB::table('accounts')->count(), 'staff' => DB::table('staff')->count(), 'requests' => DB::table('privacy_requests')->count(), 'addresses' => DB::table('addresses')->count(), 'suppressions' => DB::table('privacy_suppressions')->count()]);`;

const SEED = `
use Illuminate\\Support\\Facades\\{DB, Schema};
use EasyCo\\Staff\\Staff;
use EasyCo\\Staff\\Contracts\\{RoleRepository, StaffRepository, PasswordHasher};
$role = app(RoleRepository::class)->findSystemRoleByName('Administrator');
$staff = Staff::create('${STAFF}', app(PasswordHasher::class)->hash('${PASSWORD}'), 'Audit Admin', $role);
app(StaffRepository::class)->save($staff);
$account = DB::table('accounts')->insertGetId(['email' => '${PERSON}', 'password' => 'x', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
DB::table('addresses')->insert(['account_id' => $account, 'delivery_type' => 'street_address', 'recipient_first_name' => 'Мария', 'recipient_last_name' => 'Петрова', 'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Пловдив', 'postal_code' => '4000', 'address_line_1' => 'ул. Примерна 12', 'default_marker' => 1, 'created_at' => now(), 'updated_at' => now()]);
$tx = DB::table('operational_sales_transactions')->value('id');
$client = DB::table('operational_sales_clients')->value('id');
Schema::disableForeignKeyConstraints();
$ids = [];
foreach ([['delivered', 12000, 10], ['confirmed', 4590, 2]] as [$status, $total, $ago]) {
    $ids[] = DB::table('orders')->insertGetId(['client_id' => $client ?? 1, 'transaction_id' => $tx ?? 1, 'email' => '${PERSON}', 'email_key' => '${PERSON}', 'currency' => 'EUR', 'subtotal_minor' => $total, 'discount_minor' => 0, 'shipping_minor' => 0, 'total_minor' => $total, 'status' => $status, 'placed_at' => now()->subDays($ago), 'delivery_type' => 'street_address', 'phone' => '+359888123456', 'phone_key' => '+359888123456', 'recipient_first_name' => 'Мария', 'recipient_last_name' => 'Петрова', 'name_key' => 'мария петрова', 'account_id' => $account, 'marketing_consent' => 0, 'created_at' => now(), 'updated_at' => now()]);
}
Schema::enableForeignKeyConstraints();
$h = fn ($e) => app(App\\Privacy\\PrivacyHash::class)->email($e);
DB::table('privacy_requests')->insert(['kind' => 'erase', 'source' => 'admin', 'status' => 'confirmed', 'email' => '${PERSON}', 'email_hash' => $h('${PERSON}'), 'staff_name' => 'Audit Admin', 'confirmed_at' => now()->subHour(), 'created_at' => now()->subDay(), 'updated_at' => now()]);
DB::table('privacy_requests')->insert(['kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email' => '${ERASED1}', 'email_hash' => $h('${ERASED1}'), 'phone' => '+359877000111', 'reason' => 'Клиентът поиска по имейл', 'staff_name' => 'Audit Admin', 'actions' => json_encode(['account_deleted' => true, 'addresses_deleted' => 2, 'carts_deleted' => 1, 'staff_note_deleted' => true, 'suppressed_email' => true, 'suppressed_phone' => true, 'cod_blocks_kept' => '2027-02-01 10:00:00', 'hook_customer_erased' => true, 'mailchimp.removed' => true]), 'manual_steps' => json_encode([['key' => 'sfb1', 'done_at' => now()->toIso8601String(), 'staff_id' => 1]]), 'completed_at' => now()->subDays(27), 'clear_until' => now()->addDays(3), 'created_at' => now()->subDays(27), 'updated_at' => now()]);
DB::table('privacy_requests')->insert(['kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email' => null, 'email_hash' => $h('${ERASED2}'), 'reason' => 'Стар запис', 'staff_name' => 'Audit Admin', 'actions' => json_encode(['account_deleted' => true]), 'completed_at' => now()->subDays(60), 'clear_until' => now()->subDays(30), 'created_at' => now()->subDays(60), 'updated_at' => now()]);
$repo = app(App\\Settings\\Contracts\\SiteSettingsRepository::class);
$old = $repo->get('privacy.manual_steps');
$repo->set('privacy.manual_steps', json_encode([['key' => 'sfb1', 'label' => 'Махнат от аудиторията във Facebook'], ['key' => 'smc1', 'label' => 'Махнат от Mailchimp']], JSON_UNESCAPED_UNICODE));
echo json_encode(['order' => $ids[0], 'old' => $old]);
`;

const clean = (old) => `
use Illuminate\\Support\\Facades\\{DB, Schema};
$h = fn ($e) => app(App\\Privacy\\PrivacyHash::class)->email($e);
Schema::disableForeignKeyConstraints();
DB::table('orders')->where('email_key', '${PERSON}')->delete();
Schema::enableForeignKeyConstraints();
DB::table('privacy_requests')->whereIn('email_hash', [$h('${PERSON}'), $h('${ERASED1}'), $h('${ERASED2}')])->delete();
$acc = DB::table('accounts')->where('email', '${PERSON}')->value('id');
DB::table('addresses')->where('account_id', $acc)->delete();
DB::table('accounts')->where('email', '${PERSON}')->delete();
DB::table('staff')->where('email', '${STAFF}')->delete();
$repo = app(App\\Settings\\Contracts\\SiteSettingsRepository::class);
$old = json_decode('${old}', true);
if ($old === null) { DB::table('site_settings')->where('key', 'privacy.manual_steps')->delete(); } else { $repo->set('privacy.manual_steps', $old); }
echo 'cleaned';
`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const before = php(COUNTS);
const result = { before, widths: [], failed: 0 };
let browser = null;
let old = 'null';

try {
    const seeded = JSON.parse(php(SEED));
    old = JSON.stringify(seeded.old ?? null).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: true });

    for (const [label, width, height] of [['wide', 1280, 900], ['phone', 360, 800]]) {
        const context = await browser.newContext({ viewport: { width, height }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        await page.goto(BASE + '/admin/login');
        await page.fill('input[type=email]', STAFF);
        await page.fill('input[type=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
        const entry = { label, width };
        const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);

        // the tile on the dashboard
        await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
        entry.tile = { overflowX: await overflow(), shown: await page.locator('text=/Заявка за забравяне|Forget request/').count() > 0 };
        await page.screenshot({ path: join(OUT, `tile-${label}.png`), fullPage: false });

        // the log
        await page.goto(BASE + '/admin/privacy-log', { waitUntil: 'networkidle' });
        entry.log = await page.evaluate(() => ({ overflowX: document.documentElement.scrollWidth > window.innerWidth + 1, rows: document.querySelectorAll('tbody tr').length, checkboxes: document.querySelectorAll('tbody input[type=checkbox]').length }));
        await page.screenshot({ path: join(OUT, `log-${label}.png`), fullPage: true });

        // the lookup (an email that was never forgotten: nothing is written)
        await page.fill('#pl-email', 'nobody-' + TAG + '@example.test');
        await page.getByRole('button', { name: /Провери|Check/ }).click();
        await page.waitForTimeout(800);
        entry.lookupAnswered = await page.locator('.pl-answer').count() > 0;

        // the settings
        await page.goto(BASE + '/admin/privacy-settings', { waitUntil: 'networkidle' });
        entry.settings = await page.evaluate(() => ({ overflowX: document.documentElement.scrollWidth > window.innerWidth + 1, inputs: document.querySelectorAll('input[type=number], input[type=text]').length }));
        await page.screenshot({ path: join(OUT, `settings-${label}.png`), fullPage: true });

        // the dialog: opened, looked at, closed; never submitted
        await page.goto(BASE + `/admin/customers/o-${seeded.order}`, { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: /^(Забрави|Forget)$/ }).first().click();
        await page.waitForTimeout(800);
        entry.dialog = await page.evaluate(() => { const m = document.querySelector('.fi-modal-window'); const r = m?.getBoundingClientRect(); return m ? { width: Math.round(r.width), fits: r.right <= window.innerWidth + 1 && r.left >= -1, hasWarning: /в процес|in progress/.test(m.textContent), hasReason: !! m.querySelector('textarea') } : null; });
        await page.screenshot({ path: join(OUT, `dialog-${label}.png`) });
        await page.keyboard.press('Escape');

        (entry.tile.overflowX || entry.log.overflowX || entry.settings.overflowX || ! entry.dialog?.fits || ! entry.dialog?.hasReason || ! entry.tile.shown) && result.failed++;
        result.widths.push(entry);
        await context.close();
    }
} finally {
    browser && await browser.close();
    result.cleaned = php(clean(old));
    result.after = php(COUNTS);
}

console.log(JSON.stringify(result, null, 2));
process.exit(result.failed === 0 && result.before === result.after ? 0 : 1);
