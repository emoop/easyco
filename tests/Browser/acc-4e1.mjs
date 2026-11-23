// A REAL-BROWSER check of ACC-4e-1: the admin «Клиенти» list and the customer page, at 1280px and 360px (admin-ui-standard.md rule 7).
//   For each width: the list, the customer page (both columns / one column), the «Поискай потвърждение» dialog; screenshots kept OUTSIDE the project; the checks: no horizontal page scroll, the number of
//   columns of the page grid (2 on a wide screen, 1 on a phone), nothing wider than the viewport, the info icon's bubble opens, the table's own scroll container on a phone.
//   Data written to the DEV database: ONE throw-away Administrator, one customer with an account, three orders, an address, a note, one confirmed and one waiting request; at the end the script deletes
//   them again and prints the row counts before and after. NO mail is sent (the requests are written straight into the table).
// NOT part of `php artisan test`: run by hand against a dev site that has the ACC-4e-1 migration (php artisan migrate).
//
//   AUDIT_BASE=https://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> OUT=<folder outside the project> node tests/Browser/acc-4e1.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const OUT = process.env.OUT ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT) { console.error('usage: PLAYWRIGHT_DIR=... OUT=<folder outside the project> node acc-4e1.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

// one run of PHP through tinker's --execute (newlines collapsed); a non-zero exit or an empty answer is an error
const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 120000 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const TAG = `acc4e1-${Date.now()}`;
const STAFF = `${TAG}-admin@example.test`;
const PASSWORD = 'Acc4e1-' + Math.random().toString(36).slice(2) + 'Aa1!';
const PERSON = `${TAG}-person@example.test`;

const COUNTS = `use Illuminate\\Support\\Facades\\DB; echo json_encode(['orders' => DB::table('orders')->count(), 'accounts' => DB::table('accounts')->count(), 'staff' => DB::table('staff')->count(), 'requests' => DB::table('privacy_requests')->count(), 'notes' => DB::table('customer_notes')->count(), 'addresses' => DB::table('addresses')->count()]);`;

const SEED = `
use Illuminate\\Support\\Facades\\{DB, Schema};
use EasyCo\\Staff\\{Staff};
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
foreach ([['placed', 4590, 0], ['delivered', 12000, 1], ['cancelled', 3000, 0]] as $i => [$status, $total, $consent]) {
    $ids[] = DB::table('orders')->insertGetId(['client_id' => $client ?? 1, 'transaction_id' => $tx ?? 1, 'email' => '${PERSON}', 'email_key' => '${PERSON}', 'currency' => 'EUR', 'subtotal_minor' => $total, 'discount_minor' => 0, 'shipping_minor' => 0, 'total_minor' => $total, 'status' => $status, 'placed_at' => now()->subDays(10 - $i * 3), 'delivery_type' => 'street_address', 'phone' => '+359888123456', 'phone_key' => '+359888123456', 'recipient_first_name' => 'Мария', 'recipient_last_name' => 'Петрова', 'name_key' => 'мария петрова', 'account_id' => $account, 'marketing_consent' => $consent, 'marketing_consent_text' => $consent ? 'Искам да получавам новини' : null, 'created_at' => now(), 'updated_at' => now()]);
}
Schema::enableForeignKeyConstraints();
$hash = app(App\\Privacy\\PrivacyHash::class)->email('${PERSON}');
DB::table('privacy_requests')->insert([
  ['kind' => 'export', 'source' => 'admin', 'status' => 'confirmed', 'email' => '${PERSON}', 'email_hash' => $hash, 'staff_name' => 'Audit Admin', 'confirmed_at' => now()->subHour(), 'created_at' => now()->subDay(), 'updated_at' => now()],
  ['kind' => 'erase', 'source' => 'admin', 'status' => 'awaiting_confirmation', 'email' => '${PERSON}', 'email_hash' => $hash, 'staff_name' => 'Audit Admin', 'confirmed_at' => null, 'created_at' => now()->subDays(2), 'updated_at' => now()],
]);
DB::table('customer_notes')->insert(['email_key' => '${PERSON}', 'body' => "Звъни след 18:00.\\nПредпочита куриер Еконт.", 'staff_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
echo $ids[0];
`;

const CLEAN = `
use Illuminate\\Support\\Facades\\{DB, Schema};
$hash = app(App\\Privacy\\PrivacyHash::class)->email('${PERSON}');
Schema::disableForeignKeyConstraints();
DB::table('orders')->where('email_key', '${PERSON}')->delete();
Schema::enableForeignKeyConstraints();
DB::table('privacy_requests')->where('email_hash', $hash)->delete();
DB::table('customer_notes')->where('email_key', '${PERSON}')->delete();
$acc = DB::table('accounts')->where('email', '${PERSON}')->value('id');
DB::table('addresses')->where('account_id', $acc)->delete();
DB::table('accounts')->where('email', '${PERSON}')->delete();
DB::table('staff')->where('email', '${STAFF}')->delete();
echo 'cleaned';
`;

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'package.json'))('playwright');
const before = php(COUNTS);
const result = { before, widths: [], failed: 0 };
let browser = null;

try {
    const orderId = php(SEED);
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

        await page.goto(BASE + '/admin/customers', { waitUntil: 'networkidle' });
        entry.list = await page.evaluate(() => ({ overflowX: document.documentElement.scrollWidth > window.innerWidth + 1, rows: document.querySelectorAll('tbody tr').length, tabs: document.querySelectorAll('.fi-tabs-item, [role=tab]').length }));
        await page.screenshot({ path: join(OUT, `list-${label}.png`), fullPage: true });

        await page.goto(BASE + `/admin/customers/o-${orderId}`, { waitUntil: 'networkidle' });
        entry.page = await page.evaluate(() => {
            const grid = document.querySelector('.cu-grid');
            return {
                overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
                columns: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : null,
                wideChildren: [...document.querySelectorAll('.cu-grid *')].filter((el) => el.getBoundingClientRect().right > window.innerWidth + 1 && ! el.closest('.cu-scroll')).length,
                sections: document.querySelectorAll('.cu-grid section, .cu-grid .fi-section').length,
                helperParagraphsUnderNote: document.querySelectorAll('.cu-note p').length,
            };
        });
        await page.screenshot({ path: join(OUT, `page-${label}.png`), fullPage: true });

        // the info icon next to «Бележка»: the bubble opens on hover/focus
        const hint = page.locator('.cu-title .info-hint-btn').first();
        if (await hint.count()) {
            await hint.focus();
            await page.waitForTimeout(250);
            entry.hintBubbleVisible = await page.locator('.cu-title .info-hint [role=tooltip]').first().isVisible().catch(() => null);
            await page.screenshot({ path: join(OUT, `hint-${label}.png`) });
        }

        // the dialog «Поискай потвърждение»
        await page.getByRole('button', { name: /Поискай потвърждение|Request confirmation/ }).first().click();
        await page.waitForTimeout(700);
        await page.screenshot({ path: join(OUT, `dialog-${label}.png`) });
        entry.dialog = await page.evaluate(() => { const m = document.querySelector('.fi-modal-window'); const r = m?.getBoundingClientRect(); return m ? { width: Math.round(r.width), fits: r.right <= window.innerWidth + 1 && r.left >= -1 } : null; });

        (entry.list.overflowX || entry.page.overflowX || entry.page.wideChildren > 0 || (width >= 1000 && entry.page.columns !== 2) || (width < 700 && entry.page.columns !== 1) || (entry.dialog && ! entry.dialog.fits)) && result.failed++;
        result.widths.push(entry);
        await context.close();
    }
} finally {
    browser && await browser.close();
    result.cleaned = php(CLEAN);
    result.after = php(COUNTS);
}

console.log(JSON.stringify(result, null, 2));
process.exit(result.failed === 0 && result.before === result.after ? 0 : 1);
