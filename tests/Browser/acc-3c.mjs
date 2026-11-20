// A REAL-BROWSER check of ACC-3c: the logged-in checkout (saved addresses, «Поръчвате като», the recipient email of the delivery mails).
//   Flows, each at 1280px and 390px (≤ 40rem), screenshots kept OUTSIDE the project:
//    1. guest: «Имате профил? Вход» → the login page → back in /checkout with THE SAME cart (the guest's line is still there; the cart id is a new one after the login merge);
//    2. logged in: «Поръчвате като {email} · Не сте вие? Изход», no email field, the default address preselected with its fields hidden, the first 3 rows + «Още адреси (N)»;
//    3. a second address chosen: the fields follow, the order goes out as its address_id;
//    4. «Промени»: the fields show and are editable, the order goes out as the full fields (no address_id);
//    5. «Нов адрес» for another person: name and phone prefilled from the default, the address empty, «Изпращам до друг човек» → the recipient email travels;
//    6. an office/locker: only the saved points of the SAME courier and kind, the usual single field when there are none; courier and kind switched several times;
//    7. an ended session (the session cookie is deleted before the click): the message «Сесията изтече — …», the email field is back, what was typed is kept.
//   NO ORDER IS PLACED: POST /api/checkout is intercepted (page.route) and answered with a canned reply; its body is what the checks read. The only data written to the dev database is ONE throw-away
//   account (verified, with a known password), its addresses, and the cart row of the browser; at the end the script deletes them again and prints the row counts before/after.
// NOT part of `php artisan test`: run by hand against a dev site that has the ACC-3c migration (php artisan migrate) and a built front end (npm run build).
//
//   AUDIT_BASE=http://easyco-main.test PLAYWRIGHT_DIR=<dir with node_modules/playwright> [CHROMIUM=<path>] VARIATION_ID=<id of an in-stock variation> OUT=<folder outside the project> node tests/Browser/acc-3c.mjs
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'http://easyco-main.test';
const OUT = process.env.OUT ?? '';
const VARIATION = process.env.VARIATION_ID ?? '';
const ROOT = resolve(import.meta.dirname, '..', '..');

if (! process.env.PLAYWRIGHT_DIR || ! OUT || ! VARIATION) { console.error('usage: PLAYWRIGHT_DIR=... VARIATION_ID=<in-stock variation> OUT=<folder outside the project> node acc-3c.mjs'); process.exit(2); }
if (resolve(OUT).toLowerCase().startsWith(ROOT.toLowerCase())) { console.error('refused: OUT is inside the project folder; the screenshots must be kept outside it'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

// one line of PHP through tinker (newlines collapsed); a non-zero exit or an empty answer is an error
const php = (code) => {
    const r = spawnSync('php', ['artisan', 'tinker', '--execute', code.replace(/\s*\n\s*/g, ' ')], { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const out = (r.stdout ?? '').trim();
    if (r.status !== 0 || out === '') { throw new Error(`php failed (status ${r.status}): ` + (out + '\n' + (r.stderr ?? '')).slice(-900)); }
    return out.split('\n').pop();
};

const EMAIL = `acc3c-${Date.now()}@example.test`;
const PASSWORD = 'Acc3c-' + Math.random().toString(36).slice(2) + 'Aa1!';

const COUNTS = String.raw`
use Illuminate\Support\Facades\DB;
echo json_encode(['accounts' => DB::table('accounts')->count(), 'addresses' => DB::table('addresses')->count(), 'orders' => DB::table('orders')->count(), 'carts' => DB::table('carts')->count(), 'cart_lines' => DB::table('cart_lines')->count()]);`;

// the throw-away account and its book: three streets (the first is the default), a fourth street, an Еконт office, a Спиди office, an Еконт locker
const SEED = String.raw`
use Illuminate\Support\Facades\{DB, Hash};
$id = DB::table('accounts')->insertGetId(['email' => '${EMAIL}', 'password' => Hash::make('${PASSWORD}'), 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$base = ['account_id' => $id, 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888111111', 'created_at' => now(), 'updated_at' => now(), 'delivery_type' => null, 'country' => 'BG', 'city' => null, 'postal_code' => null, 'address_line_1' => null, 'carrier_code' => null, 'pickup_point_kind' => null, 'settlement' => null, 'pickup_point_address' => null, 'default_marker' => null];
$street = fn ($n, $city, $extra = []) => array_merge($base, ['delivery_type' => 'street_address', 'city' => $city, 'postal_code' => '1000', 'address_line_1' => 'Street '.$n], $extra);
$point = fn ($carrier, $kind, $where) => array_merge($base, ['delivery_type' => 'pickup_point', 'carrier_code' => $carrier, 'pickup_point_kind' => $kind, 'settlement' => 'Sofia', 'pickup_point_address' => $where]);
DB::table('addresses')->insert([$street(1, 'Sofia', ['default_marker' => 1]), $street(2, 'Plovdiv', ['recipient_first_name' => 'Maria', 'phone' => '+359888222222']), $street(3, 'Varna'), $street(4, 'Burgas'), $point('Еконт', 'office', 'Econt Center 1'), $point('Спиди', 'office', 'Speedy Office 2'), $point('Еконт', 'locker', 'Econt Locker 3')]);
echo $id;`;

const clean = () => String.raw`
use Illuminate\Support\Facades\DB;
$id = DB::table('accounts')->where('email', '${EMAIL}')->value('id'); DB::table('cart_lines')->whereIn('cart_id', DB::table('carts')->where('id', '>', ${CART_FLOOR})->pluck('id'))->delete(); DB::table('carts')->where('id', '>', ${CART_FLOOR})->delete();
if ($id) { DB::table('addresses')->where('account_id', $id)->delete(); DB::table('carts')->where('account_id', $id)->get()->each(function ($c) { DB::table('cart_lines')->where('cart_id', $c->id)->delete(); DB::table('carts')->where('id', $c->id)->delete(); }); DB::table('accounts')->where('id', $id)->delete(); }
echo 'ok';`;

const before = JSON.parse(php(COUNTS));
const CART_FLOOR = php("echo DB::table('carts')->max('id') ?? 0;");
php(SEED);

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ['--ignore-certificate-errors'] });

const fails = [];
const check = (ok, text) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${text}`); if (! ok) { fails.push(text); } };
const shot = (page, name) => page.screenshot({ path: join(OUT, `${name}.png`), fullPage: true });

// POST /api/checkout is never sent to the server: the body is kept and a canned reply is returned (a field error, so the form stays usable)
// the terms box (when the merchant asks for it) is ticked, the button waited for, then pressed
async function press(page) {
    const terms = page.locator('input[name="terms_accepted"]');
    if (await terms.count() > 0 && ! await terms.isChecked()) { await terms.check(); }
    await page.waitForSelector('[data-sf-place-order]:not([disabled])', { timeout: 15000 });
    await page.click('[data-sf-place-order]');
    await page.waitForTimeout(700);
}

async function interceptCheckout(page, reply = { status: 422, body: { message: 'x', errors: { customer_note: ['x'] } } }) {
    const bodies = [];
    await page.route('**/api/checkout', async (route) => {
        bodies.push(JSON.parse(route.request().postData() ?? '{}'));
        await route.fulfill({ status: reply.status, contentType: 'application/json', body: JSON.stringify(reply.body) });
    });

    return bodies;
}

const rows = (page) => page.locator('[data-sf-saved-list] label').allTextContents().then((t) => t.map((s) => s.replace(/\s+/g, ' ').trim()));
// the same CSRF dance storefront-cart.js does, inside the page: GET /sanctum/csrf-cookie, then the write with X-XSRF-TOKEN
const addLine = (page) => page.evaluate(async (variation) => {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers });
    const token = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').slice(11));
    const response = await fetch('/api/cart/lines', { method: 'POST', credentials: 'same-origin', headers: { ...headers, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ variation_id: variation, quantity: 1 }) });

    return response.status;
}, VARIATION);

async function login(page, redirect) {
    await page.goto(`${BASE}/account/login?redirect=${redirect}`, { waitUntil: 'load' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([page.waitForResponse((r) => r.url().includes('/api/account/login') && r.request().method() === 'POST', { timeout: 15000 }), page.click('[data-sf-account-submit]')]);
    await page.waitForURL((url) => ! url.pathname.startsWith('/account/login'), { timeout: 15000 });
}

async function readyCheckout(page) {
    await page.waitForSelector('[data-sf-checkout-form]');
    await page.waitForFunction(() => document.querySelector('[data-sf-checkout-loading]')?.hidden === true, null, { timeout: 15000 });
    await page.waitForTimeout(800);
}

async function pickLocalCourier(page) {
    // the first courier that has an ADDRESS kind: click it and, when a kind select appears, take the address kind
    const couriers = page.locator('[data-sf-couriers] label');
    for (let i = 0; i < await couriers.count(); i += 1) {
        await couriers.nth(i).click();
        const select = page.locator('[data-sf-kind-select]');
        if (await select.isVisible()) {
            const value = await select.locator('option', { hasText: /./ }).evaluateAll((os) => os.map((o) => o.value).find((v) => v.endsWith(':address')) ?? null);
            if (value) { await select.selectOption(value); return true; }
        }
        if (await page.locator('[data-sf-address-fields]').isVisible()) { return true; }
    }
    return false;
}

for (const width of [1280, 390]) {
    const tag = String(width);

    // ---- 1. guest: «Вход» → login → back with the same cart ------------------------------------------------------------------------------
    {
        const context = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true });
        const page = await context.newPage();
        await page.goto(`${BASE}/`, { waitUntil: 'load' });
        const added = await addLine(page);
        check(added === 201, `${tag}: a guest cart line was added (${added})`);
        await page.goto(`${BASE}/checkout`, { waitUntil: 'load' });
        await readyCheckout(page);
        check(await page.locator('[data-sf-login-hint]').isVisible(), `${tag}: the guest sees «Имате профил? Вход»`);
        check(await page.locator('input[name="email"]').isVisible(), `${tag}: and the email field`);
        await shot(page, `${tag}-1-guest`);
        const guestLines = await page.locator('[data-sf-summary-lines] > *').count();

        await Promise.all([page.waitForURL('**/account/login**'), page.click('[data-sf-login-link]')]);
        await login(page, '/checkout');
        await readyCheckout(page);
        check((await page.locator('[data-sf-summary-lines] > *').count()) === guestLines && guestLines > 0, `${tag}: back in the checkout with the same cart (${guestLines} line)`);
        check(await page.locator('[data-sf-order-as]').isVisible(), `${tag}: «Поръчвате като» is shown after the login`);
        await context.close();
    }

    // ---- 2-6. logged in ---------------------------------------------------------------------------------------------------------------------
    const context = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await login(page, '/account');
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await addLine(page); // the guest line of flow 1 was merged into the account cart at login: the cart is not empty whatever this answers
    const reply = { status: 422, body: { message: 'x', errors: { customer_note: ['x'] } } };
    const bodies = await interceptCheckout(page, reply);
    await page.goto(`${BASE}/checkout`, { waitUntil: 'load' });
    await readyCheckout(page);

    check(await page.locator('[data-sf-order-as]').innerText().then((t) => t.includes(EMAIL) && /Изход/.test(t)), `${tag}: «Поръчвате като ${EMAIL} · Не сте вие? Изход»`);
    check(! await page.locator('input[name="email"]').isVisible(), `${tag}: no email field`);
    let list = await rows(page);
    check(list.length === 4 && /Street 1/.test(list[0]) && /Нов адрес/.test(list[3]), `${tag}: three saved rows and «Нов адрес»: ${JSON.stringify(list)}`);
    check(await page.locator('[data-sf-saved-more]').isVisible(), `${tag}: «Още адреси (1)» is offered`);
    check(await page.locator('[data-sf-saved-list] input').first().isChecked(), `${tag}: the default is preselected`);
    check(! await page.locator('#sf-city').isVisible(), `${tag}: its fields are hidden`);
    await shot(page, `${tag}-2-default`);

    // 3. the second address
    await page.locator('[data-sf-saved-list] input').nth(1).click();
    await page.waitForTimeout(900);
    check(await page.inputValue('#sf-city') === 'Plovdiv' && await page.inputValue('input[name="recipient_first_name"]') === 'Maria', `${tag}: the second address fills the form`);
    await shot(page, `${tag}-3-second`);
    check(await pickLocalCourier(page), `${tag}: a courier with an address kind was chosen`);
    await page.waitForTimeout(900);
    await press(page);
    check(bodies.length === 1 && typeof bodies[0].address_id === 'string' && ! ('email' in bodies[0]) && ! ('city' in bodies[0]), `${tag}: the order goes out as address_id only, without an email: ${JSON.stringify(bodies[0] ?? null).slice(0, 160)}`);

    // 4. «Промени»
    await page.locator('[data-sf-saved-list] button').first().click();
    check(await page.locator('#sf-city').isVisible(), `${tag}: «Промени» shows the fields`);
    await page.fill('#sf-address-1', 'Changed 77');
    await press(page);
    const changed = bodies.at(-1);
    check(bodies.length === 2 && ! ('address_id' in changed) && changed.address_line_1 === 'Changed 77' && changed.delivery_type === 'street_address', `${tag}: changed → the full fields, no address_id`);
    await shot(page, `${tag}-4-change`);

    // 5. «Нов адрес» for another person, with a recipient email
    await page.locator('[data-sf-saved-list] label').last().click();
    await page.waitForTimeout(900);
    check(await page.inputValue('input[name="recipient_first_name"]') === 'Ivan' && await page.inputValue('#sf-city') === '', `${tag}: «Нов адрес»: the person from the default address, the address empty`);
    await page.fill('input[name="recipient_first_name"]', 'Mama');
    await page.click('[data-sf-recipient-toggle]');
    await page.fill('#sf-recipient-email', 'Mum@Example.com');
    await page.fill('#sf-city', 'Varna');
    await page.waitForTimeout(900);
    await pickLocalCourier(page);
    await page.fill('#sf-address-1', 'Sea 1');
    await page.waitForTimeout(900);
    await press(page);
    const other = bodies.at(-1);
    check(bodies.length === 3 && other.recipient_first_name === 'Mama' && other.recipient_email === 'Mum@Example.com' && ! ('address_id' in other), `${tag}: another person, with the recipient email`);
    await shot(page, `${tag}-5-new-for-other`);
    await page.click('[data-sf-recipient-toggle]');
    check(await page.inputValue('#sf-recipient-email') === '', `${tag}: closing the recipient field clears it`);

    // 6. offices and lockers: only the saved points of the same courier and kind
    await page.locator('[data-sf-saved-list] input').first().click();
    await page.waitForTimeout(900);
    const couriers = page.locator('[data-sf-couriers] label');
    const names = await couriers.allTextContents();
    for (let i = 0; i < names.length; i += 1) {
        await couriers.nth(i).click();
        const select = page.locator('[data-sf-kind-select]');
        const options = await select.isVisible() ? await select.locator('option').evaluateAll((os) => os.map((o) => ({ value: o.value, text: o.textContent }))) : [];
        for (const option of options.filter((o) => /^[0-9]+:(address|office|locker)$/.test(o.value))) {
            await select.selectOption(option.value);
            await page.waitForTimeout(250);
            const kind = option.value.split(':')[1];
            const courier = names[i].includes('Еконт') ? 'Еконт' : names[i].includes('Спиди') ? 'Спиди' : '?';
            const shown = await rows(page);
            const points = shown.filter((r) => /Center|Office|Locker/.test(r));
            // exactly the saved points of ITS courier and kind; a street kind shows streets only
            const expected = kind === 'office' ? (courier === 'Еконт' ? ['Econt Center 1'] : courier === 'Спиди' ? ['Speedy Office 2'] : []) : kind === 'locker' ? (courier === 'Еконт' ? ['Econt Locker 3'] : []) : [];
            const ok = points.length === expected.length && expected.every((e, n) => points[n].includes(e));
            check(ok, `${tag}: courier «${courier}», kind ${kind}: rows ${JSON.stringify(shown)}`);
            check(kind === 'address' ? points.length === 0 : (expected.length === 0 ? await page.locator('[data-sf-pickup-fields]').isVisible() : ! await page.locator('#sf-city').isVisible()), `${tag}: …and the form matches (${expected.length === 0 ? 'the usual single field' : 'the saved point, fields hidden'})`);
        }
    }
    await shot(page, `${tag}-6-offices`);

    // 7. an ended session
    await page.locator('[data-sf-saved-list] input').first().click().catch(() => {});
    await pickLocalCourier(page);
    await page.fill('textarea[name="customer_note"]', 'Please call');
    // the session ending is simulated by the answer to the click (a guest request without an email: the API's own 422 for it, proven in AccountCheckoutTest);
    // NOTHING reaches the server, so no order can be placed here
    reply.status = 422;
    reply.body = { message: 'x', errors: { email: ['The email field is required.'] } };
    await press(page);
    check(await page.locator('input[name="email"]').isVisible(), `${tag}: after the session ended the email field is back`);
    check(/Сесията изтече/.test(await page.locator('[data-sf-error-summary]').innerText()), `${tag}: with the message «Сесията изтече — …»`);
    check(await page.inputValue('textarea[name="customer_note"]') === 'Please call', `${tag}: and what was typed is kept`);
    await shot(page, `${tag}-7-expired`);
    check(errors.length === 0, `${tag}: no page errors ${errors.join(' | ')}`);
    await context.close();
}

await browser.close();
php(clean());
const after = JSON.parse(php(COUNTS));
check(JSON.stringify(after) === JSON.stringify(before), `the dev database is as it was: ${JSON.stringify(before)} → ${JSON.stringify(after)}`);
console.log(fails.length === 0 ? 'ALL PASS' : `${fails.length} FAILED`);
process.exit(fails.length === 0 ? 0 : 1);
