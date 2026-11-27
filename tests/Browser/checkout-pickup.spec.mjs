// Real-browser check of the checkout delivery block (see README.md). Not part of CI.
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const OUT = 'tests/Browser/out';
const PRODUCT = process.env.PW_PRODUCT || 'pantaloni-rips';
let users;

test.beforeAll(() => {
    mkdirSync(OUT, { recursive: true });
    users = JSON.parse(execFileSync('php', ['tests/Browser/support/users.php', 'create'], { encoding: 'utf8' }));
});

test.afterAll(() => {
    execFileSync('php', ['tests/Browser/support/users.php', 'delete'], { encoding: 'utf8' });
});

async function loginStaff(page) {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(users.staff.email);
    await page.locator('input[type="password"]').fill(users.staff.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
}

async function loginCustomer(page, who = users.customer) {
    await page.goto('/');
    await page.request.get('/sanctum/csrf-cookie');
    const cookies = await page.context().cookies();
    const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    // many throwaway customers log in one after the other: the login limiter (per IP) may ask to wait, and that is respected, not bypassed
    let response;
    for (let attempt = 0; attempt < 4; attempt++) {
        response = await page.request.post('/api/account/login', {
            headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', Referer: page.url() },
            data: { email: who.email, password: who.password },
        });
        if (response.status() !== 429) break;
        await page.waitForTimeout((Math.min(Number(response.headers()['retry-after'] || 30), 70) + 1) * 1000);
    }
    expect(response.status(), 'customer login: ' + (await response.text()).slice(0, 200)).toBeLessThan(300);
}

/** The cookie dialog makes the page inert until it is answered (a customer's first visit). */
async function refuseCookies(page) {
    const refuse = page.locator('[data-sf-cc-action="reject"]').first();
    if (await refuse.isVisible({ timeout: 2500 }).catch(() => false)) await refuse.click();
}

async function addToCart(page) {
    await page.goto(`/product/${PRODUCT}`);
    await refuseCookies(page);
    const box = page.locator('[data-sf-add-to-cart]');
    const variant = box.locator('select, input[type="radio"]');
    if (await variant.count()) {
        for (const select of await box.locator('select').all()) {
            await select.selectOption({ index: 1 }).catch(() => {});
        }
    }
    let response;
    // the local Apache now and then answers a request from a half-booted app (a 500 that is not ours); it is asked again, never ignored
    for (let attempt = 0; attempt < 3; attempt++) {
        [response] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/cart/lines') && r.request().method() === 'POST'),
            box.locator('.sf-add-to-cart').click(),
        ]);
        if (response.status() !== 500) break;
        await page.waitForTimeout(1500);
    }
    expect(response.status(), 'add to cart').toBeLessThan(300);
}

function record(page, session) {
    const log = { requests: [], console: [], pageErrors: [] };
    page.on('response', (r) => {
        const url = new URL(r.url());
        if (url.pathname.startsWith('/api/')) log.requests.push(`${r.request().method()} ${url.pathname}${url.search} -> ${r.status()}`);
    });
    page.on('console', (m) => log.console.push(`[${m.type()}] ${m.text()}`));
    page.on('pageerror', (e) => log.pageErrors.push(String(e)));
    log.save = () => writeFileSync(`${OUT}/${session}-log.json`, JSON.stringify(log, null, 2));

    return log;
}

const shot = (page, session, name) => page.screenshot({ path: `${OUT}/${session}-${name}.png`, fullPage: true });

for (const session of ['staff', 'guest', 'customer']) {
    test(`checkout pickup: ${session}`, async ({ page }) => {
        const log = record(page, session);

        if (session === 'staff') await loginStaff(page);
        if (session === 'customer') await loginCustomer(page);

        await addToCart(page);
        await page.goto('/checkout');
        await expect(page.locator('[data-sf-checkout-form]')).toBeVisible();
        await shot(page, session, '1-checkout');

        await page.locator('[data-sf-couriers] label', { hasText: 'Еконт' }).locator('input').check();
        const office_value = await page.locator('[data-sf-kind-select] option[value$=":office"]').getAttribute('value');
        await page.locator('[data-sf-kind-select]').selectOption(office_value);
        await shot(page, session, '2-courier-office');

        // who hides / shows the list area over time (a DOM log, not a guess)
        await page.evaluate(() => {
            window.__dom = [];
            const t0 = performance.now();
            const note = (what) => window.__dom.push(`${Math.round(performance.now() - t0)}ms ${what}`);
            const host = document.querySelector('[data-sf-picker]');
            const fields = document.querySelector('[data-sf-pickup-fields]');
            new MutationObserver(() => note(`list hidden=${host.hidden} (shown=${host.getBoundingClientRect().height > 0}) fields hidden=${fields.hidden} radios=${host.querySelectorAll('input[type=radio]').length} id=${document.querySelector('[name=pickup_point_id]')?.value}`))
                .observe(document.querySelector('[data-sf-checkout-form]'), { attributes: true, childList: true, subtree: true, attributeFilter: ['hidden'] });
        });

        // THE PLACE comes after the courier and the type: one search field over our settlements
        const city = page.locator('input[name="city"]');
        await expect(city).toHaveAttribute('role', 'combobox');
        await city.pressSequentially('Харм', { delay: 80 });
        const suggestion = page.locator('[role="listbox"] [role="option"]', { hasText: 'Харманли' }).first();
        await expect(suggestion).toBeVisible({ timeout: 10000 });
        log.suggestion = await suggestion.textContent();
        expect(log.suggestion).toMatch(/Хасково/);
        expect(log.suggestion).toMatch(/6450/);
        await shot(page, session, '3-place-suggestions');

        // a postcode finds it too
        await city.fill('');
        await city.pressSequentially('6450', { delay: 80 });
        await expect(page.locator('[role="listbox"] [role="option"]', { hasText: 'Харманли' }).first()).toBeVisible({ timeout: 10000 });
        await city.fill('');
        await city.pressSequentially('Харм', { delay: 80 });
        await page.locator('[role="listbox"] [role="option"]', { hasText: 'Харманли' }).first().click();
        await expect(city).toHaveValue('Харманли');
        await expect(page.locator('input[name="postal_code"]')).toHaveValue('6450');

        // the real quote runs for the place, then the offices of that place (no place field of its own)
        const office = page.locator('[data-sf-picker] input[type="radio"]').first();
        await expect(office).toBeVisible({ timeout: 15000 });
        expect(await page.locator('[data-sf-picker] [role="combobox"]').count()).toBe(0);
        await shot(page, session, '4-office-list');
        await office.check();
        await expect(page.locator('[data-sf-picker-summary]')).toBeVisible();
        await page.waitForTimeout(2500);   // any re-quote that follows must keep the choice
        await expect(page.locator('[data-sf-picker-summary]')).toBeVisible();
        expect(await page.locator('input[name="pickup_point_id"]').inputValue()).not.toBe('');
        await shot(page, session, '5-office-chosen');

        // office -> address -> office again: the place is kept, the office is dropped
        const kind = page.locator('[data-sf-kind-select]');
        await kind.selectOption(await page.locator('[data-sf-kind-select] option[value$=":address"]').getAttribute('value'));
        await expect(page.locator('[data-sf-picker]')).toBeHidden();
        await expect(city).toHaveValue('Харманли');
        await kind.selectOption(office_value);
        await expect(page.locator('[data-sf-picker] input[type="radio"]').first()).toBeVisible({ timeout: 15000 });
        expect(await page.locator('input[name="pickup_point_id"]').inputValue()).toBe('');
        await page.locator('[data-sf-picker] input[type="radio"]').first().check();
        await expect(page.locator('[data-sf-picker-summary]')).toBeVisible();
        await shot(page, session, '6-final-office-chosen');

        log.dom = await page.evaluate(() => window.__dom);
        log.state = await page.evaluate(() => ({ hostHidden: document.querySelector('[data-sf-picker]').hidden, id: document.querySelector('[name=pickup_point_id]').value }));
        log.save();
        console.log(session, JSON.stringify(log.state), log.requests.join(' | '), log.console.join(' | '), log.pageErrors.join(' | '));
        const state = log.state;
        expect(state.hostHidden, 'the list is visible').toBe(false);
        expect(state.id, 'an office is chosen').not.toBe('');
    });
}

// The office list of a big town: every row grows with its content (a long address wraps inside the row) and no two rows overlap.
for (const [name, size] of [['desktop', { width: 1280, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    test(`office list layout: ${name}`, async ({ page }) => {
        await page.setViewportSize(size);
        await addToCart(page);
        await page.goto('/checkout');
        await refuseCookies(page);
        await page.locator('[data-sf-couriers] label', { hasText: 'Еконт' }).locator('input').check();
        await page.locator('[data-sf-kind-select]').selectOption(await page.locator('[data-sf-kind-select] option[value$=":office"]').getAttribute('value'));

        const city = page.locator('input[name="city"]');
        await city.pressSequentially('Стара Загора', { delay: 60 });
        await page.locator('[role="listbox"] [role="option"]', { hasText: 'Стара Загора' }).first().click();
        const rows = page.locator('[data-sf-picker] .sf-pickup-radios .sf-choice');
        await expect(rows.first()).toBeVisible({ timeout: 15000 });
        expect(await rows.count()).toBeGreaterThan(3);
        await page.locator('[data-sf-picker]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${OUT}/office-list-${name}.png`, fullPage: false });

        const boxes = await rows.evaluateAll((nodes) => nodes.map((n) => {
            const r = n.getBoundingClientRect();
            const text = n.querySelector('.sf-pickup-point');

            return { top: r.top, bottom: r.bottom, height: r.height, textBottom: text.getBoundingClientRect().bottom, text: text.textContent.slice(0, 40) };
        }));
        boxes.sort((a, b) => a.top - b.top);
        for (let i = 0; i < boxes.length; i++) {
            expect(boxes[i].height, `row ${i} is at least a tap target`).toBeGreaterThanOrEqual(44);
            expect(boxes[i].textBottom, `row ${i}: the text stays inside its row`).toBeLessThanOrEqual(boxes[i].bottom + 0.5);
            if (i > 0) expect(boxes[i].top, `row ${i} does not overlap row ${i - 1}`).toBeGreaterThanOrEqual(boxes[i - 1].bottom - 0.5);
        }
        // at least one row is taller than a single line: the address really wraps inside its row
        expect(Math.max(...boxes.map((b) => b.height))).toBeGreaterThan(60);
    });
}

// ---------------------------------------------------------------------------------------------------------------------------------- COUR-2a.5
// A place that is ALREADY in the field (a saved address) is resolved by itself when the office / locker list is needed. The throwaway customers come from
// tests/Browser/support/users.php (a saved address each; the same-named places come from the real settlements table).

async function openCheckoutAs(page, who) {
    const log = record(page, `cour-2a5-${who}`);
    await loginCustomer(page, users[who]);
    // an account's cart is kept between visits: one that already holds the product is used as it is (adding again could pass the stock)
    const held = await page.request.get('/api/cart', { headers: { Accept: 'application/json', Referer: page.url() } });
    if (held.status() !== 200 || !((await held.json()).lines ?? []).length) await addToCart(page);
    await page.goto('/checkout');
    await refuseCookies(page);
    await expect(page.locator('[data-sf-checkout-form]')).toBeVisible();
    await expect(page.locator('[data-sf-couriers] label').first()).toBeVisible({ timeout: 20000 });

    return log;
}

/** The saved addresses come from GET /api/addresses after load; when the local Apache answered that call from a half-booted app (see addToCart) the page is loaded once more. */
async function savedListShown(page) {
    const saved = page.locator('[data-sf-saved]');
    if (!(await saved.isVisible({ timeout: 10000 }).catch(() => false))) {
        await page.reload();
        await expect(page.locator('[data-sf-couriers] label').first()).toBeVisible({ timeout: 20000 });
    }
    await expect(saved).toBeVisible({ timeout: 20000 });
}

/** The courier's radio, then the kind (a select for several, plain text for one). false = this courier offers no such kind. */
async function pickMethod(page, courier, kind) {
    const radio = page.locator('[data-sf-couriers] label', { hasText: courier }).first().locator('input');
    if (await radio.count() === 0) return false;
    await radio.check();
    const select = page.locator('[data-sf-kind-select]');
    if (await select.isVisible()) {
        const option = select.locator(`option[value$=":${kind}"]`);
        if (await option.count() === 0) return false;
        await select.selectOption(await option.getAttribute('value'));
        return true;
    }

    return (await page.locator('[data-sf-kind-text]').textContent()).includes(kind === 'locker' ? 'автомат' : 'офис');
}

const city = (page) => page.locator('input[name="city"]');
const offices = (page) => page.locator('[data-sf-picker] input[type="radio"]');
const dom = (page) => page.evaluate(() => {
    const visible = (node) => !!node && node.getClientRects().length > 0 && getComputedStyle(node).visibility !== 'hidden';
    const field = document.querySelector('input[name="city"]');

    return {
        placeVisible: visible(document.querySelector('.sf-place-row')), city: field.value, settlementId: field.getAttribute('data-settlement-id'),
        postal: document.querySelector('input[name="postal_code"]').value, status: field.parentNode.querySelector('[role="status"]')?.textContent ?? '',
        pickerStatus: document.querySelector('[data-sf-picker] [role="status"]')?.textContent ?? '', radios: document.querySelectorAll('[data-sf-picker] input[type="radio"]').length,
    };
});

// Every combination of the owner's report, for a customer with ONE saved street address (Харманли, 6450): the place field is on the page, the place is
// resolved by itself, the postcode is in the form, the offices / lockers of that place show: no typing.
for (const [courier, kinds] of [['Еконт', ['office', 'locker']], ['Спиди', ['office', 'locker']], ['BoxNow', ['locker']]]) {
    for (const kind of kinds) {
        test(`saved address resolves by itself: ${courier} ${kind}`, async ({ page }) => {
            const log = await openCheckoutAs(page, 'saved');
            await savedListShown(page);
            expect(await pickMethod(page, courier, kind), `${courier} offers ${kind}`).toBe(true);

            if (courier === 'Еконт' && kind === 'locker') {
                // Econt has no locker in Харманли (its lockers are elsewhere): nothing to resolve to. The field is on the page and says so, never an empty box.
                await expect(page.locator('[data-sf-picker]')).toContainText('Изберете населеното място от списъка', { timeout: 20000 });
                expect((await dom(page)).placeVisible).toBe(true);
                expect((await dom(page)).status).toContain('Не намерихме населено място');
                expect((await dom(page)).radios).toBe(0);
                await page.screenshot({ path: `${OUT}/cour-2a5-saved-${courier}-${kind}.png`, fullPage: true });
                return;
            }

            await expect(city(page)).toHaveAttribute('data-settlement-id', /^\d+$/, { timeout: 20000 });
            await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
            const state = await dom(page);
            await page.screenshot({ path: `${OUT}/cour-2a5-saved-${courier}-${kind}.png`, fullPage: true });
            expect(state.placeVisible, 'the place field is on the page').toBe(true);
            expect(state.city).toBe('Харманли');
            expect(state.postal, 'the postcode is in the form').toBe('6450');
            expect(state.status, 'and shown with the place').toContain('6450');
            expect(log.requests.filter((r) => r.includes('/api/shipping/settlements')).length, 'ONE resolve').toBe(1);
            log.save();
        });
    }
}

test('saved office: the place field is on the page for Спиди + office, and «Промени» hands over to the list', async ({ page }) => {
    await openCheckoutAs(page, 'savedoffice');
    await savedListShown(page);
    expect(await pickMethod(page, 'Спиди', 'office')).toBe(true);
    await expect(page.locator('[data-sf-checkout-form]')).toHaveAttribute('data-sf-saved-mode', 'saved');
    await expect(page.locator('[data-sf-checkout-form]')).toHaveAttribute('data-sf-saved-kind', 'pickup');
    await expect(page.locator('.sf-place-row').first()).toBeVisible();
    await page.screenshot({ path: `${OUT}/cour-2a5-saved-office-speedy.png`, fullPage: true });
    await page.locator('[data-sf-saved-list] .sf-link-button').first().click();
    await expect(city(page)).toHaveAttribute('data-settlement-id', /^\d+$/, { timeout: 20000 });
    await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
});

test('a place the search does not know (the start of a name): the hint, the list opens, choosing works', async ({ page }) => {
    await openCheckoutAs(page, 'odd');
    await savedListShown(page);
    expect(await pickMethod(page, 'Еконт', 'office')).toBe(true);
    const suggestion = page.locator('[role="listbox"] [role="option"]', { hasText: 'Харманли' }).first();
    await expect(suggestion).toBeVisible({ timeout: 20000 });
    const state = await dom(page);
    expect(state.settlementId, 'nothing was chosen for the customer').toBeNull();
    expect(state.city).toBe('Харман');
    expect(state.status).toContain('Изберете населеното място от списъка');
    expect(state.pickerStatus, 'the office area says the same, not an empty box').toContain('Изберете населеното място от списъка');
    await page.screenshot({ path: `${OUT}/cour-2a5-odd-hint.png`, fullPage: true });
    await suggestion.click();
    await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
    expect((await dom(page)).city).toBe('Харманли');
});

test('two places with one name: the postcode (and region) decide, and nothing is guessed without them', async ({ page }) => {
    test.skip(!users.fixtures.ambiguous, 'no pair of same-named places with Speedy offices in this database');
    const [first, second] = users.fixtures.ambiguous;

    for (const [who, expected] of [['ambiguous_a', first], ['ambiguous_b', second]]) {
        await page.context().clearCookies();
        await openCheckoutAs(page, who);
        await savedListShown(page);
        expect(await pickMethod(page, 'Спиди', 'office')).toBe(true);
        await expect(city(page)).toHaveAttribute('data-settlement-id', expected.id, { timeout: 20000 });
        expect((await dom(page)).postal).toBe(expected.postcode);
        await page.screenshot({ path: `${OUT}/cour-2a5-${who}.png`, fullPage: true });
    }

    await page.context().clearCookies();
    await openCheckoutAs(page, 'ambiguous_none');
    await savedListShown(page);
    expect(await pickMethod(page, 'Спиди', 'office')).toBe(true);
    await expect(page.locator('[role="listbox"] [role="option"]').first()).toBeVisible({ timeout: 20000 });
    const state = await dom(page);
    expect(state.settlementId, 'two places, no postcode, no region: no guess').toBeNull();
    expect(state.status).toContain('Изберете населеното място от списъка');
    expect(state.radios).toBe(0);
    await page.screenshot({ path: `${OUT}/cour-2a5-ambiguous_none.png`, fullPage: true });
});

test('two saved addresses: the picker follows the address chosen NOW', async ({ page }) => {
    await openCheckoutAs(page, 'two');
    await savedListShown(page);
    expect(await pickMethod(page, 'Еконт', 'address')).toBe(true);
    await page.locator('[data-sf-saved-list] label', { hasText: 'Стара Загора' }).locator('input').click();
    await expect(city(page)).toHaveValue('Стара Загора');
    expect(await pickMethod(page, 'Еконт', 'office')).toBe(true);
    await expect(city(page)).toHaveAttribute('data-settlement-id', /^\d+$/, { timeout: 20000 });
    const stara = await dom(page);
    expect(stara.city).toBe('Стара Загора');
    expect(stara.postal).toBe('6000');
    await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
    await page.screenshot({ path: `${OUT}/cour-2a5-two-stara-zagora.png`, fullPage: true });

    // back to a street address, the OTHER saved one, then the office list again: no stale offices of the old place
    expect(await pickMethod(page, 'Еконт', 'address')).toBe(true);
    await page.locator('[data-sf-saved-list] label', { hasText: 'Харманли' }).locator('input').click();
    await expect(city(page)).toHaveValue('Харманли');
    expect(await pickMethod(page, 'Еконт', 'office')).toBe(true);
    await expect.poll(async () => (await dom(page)).settlementId, { timeout: 20000 }).not.toBe(stara.settlementId);
    await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
    expect((await dom(page)).city).toBe('Харманли');
});

test('autofill and edits: a change event resolves the place; typing over it drops the offices at once', async ({ page }) => {
    const log = await openCheckoutAs(page, 'customer');
    expect(await pickMethod(page, 'Еконт', 'office')).toBe(true);
    await expect(page.locator('[data-sf-picker]')).toContainText('Изберете населеното място по-горе');
    // what a browser's autofill does: set the value and announce it
    await city(page).evaluate((node) => { node.value = 'Харманли'; node.dispatchEvent(new Event('input', { bubbles: true })); node.dispatchEvent(new Event('change', { bubbles: true })); });
    await expect(city(page)).toHaveAttribute('data-settlement-id', /^\d+$/, { timeout: 20000 });
    await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
    await city(page).press('End');
    await city(page).press('Backspace');
    expect((await dom(page)).settlementId, 'the old place is gone at once').toBeNull();
    expect((await dom(page)).radios, 'and so are its offices').toBe(0);
    log.save();
});

test('the postcode is required for an address: the server refuses it, the form shows the empty required field after a press only', async ({ page }) => {
    await openCheckoutAs(page, 'nopost');
    await savedListShown(page);
    expect(await pickMethod(page, 'Еконт', 'address')).toBe(true);

    // a saved address WITHOUT a postcode is opened for editing: the empty required field is on the page, no red field on load
    await expect(page.locator('[data-sf-checkout-form]')).not.toHaveAttribute('data-sf-saved-mode', 'saved');
    const postal = page.locator('input[name="postal_code"]');
    await expect(postal).toBeVisible();
    await expect(postal).toHaveValue('');
    await expect(postal).toHaveAttribute('aria-required', 'true');
    expect(await postal.getAttribute('aria-invalid')).toBeNull();
    await expect(page.locator('label[for="sf-postal-code"] [data-sf-marker]')).toHaveCount(1);

    // the script's own check, after a press (the order is never sent): the translated error under the field
    await page.locator('input[name="payment_method"]').first().check();
    const posted = [];
    page.on('request', (request) => { if (request.url().includes('/api/checkout') && request.method() === 'POST') posted.push(request.postData()); });
    if (await page.locator('input[name="terms_accepted"]').count()) await page.locator('input[name="terms_accepted"]').check();
    await expect(page.locator('[data-sf-place-order]')).toBeEnabled({ timeout: 20000 });
    await page.locator('[data-sf-place-order]').click();
    await expect(page.locator('[data-sf-error-for="postal_code"]')).toHaveText('Въведете пощенски код.');
    expect(posted, 'nothing was sent').toHaveLength(0);
    await page.screenshot({ path: `${OUT}/cour-2a5-postcode-required.png`, fullPage: true });

    // the SERVER refuses it too (the API, not the script): the translated error, and it is accepted once filled (no postcode error; the cart is made up, nothing is written)
    await page.request.get('/sanctum/csrf-cookie');
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const body = { cart_id: '999999999', recipient_first_name: 'Тест', recipient_last_name: 'Браузър', phone: '+359888123456', payment_method: 'cash_on_delivery', delivery_type: 'street_address', country: 'BG', city: 'Харманли', address_line_1: 'ул. Тестова 1' };
    const headers = { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', Referer: page.url() };
    const refused = await page.request.post('/api/checkout', { headers, data: body });
    expect(refused.status()).toBe(422);
    expect((await refused.json()).errors.postal_code[0]).toBe('Въведете пощенски код.');
    const bad = await page.request.post('/api/checkout', { headers, data: { ...body, postal_code: '12' } });
    expect((await bad.json()).errors.postal_code[0]).toBe('Пощенският код трябва да е от 4 цифри.');
    const accepted = await page.request.post('/api/checkout', { headers, data: { ...body, postal_code: '6450' } });
    expect((await accepted.json()).errors?.postal_code, 'no postcode error once it is filled').toBeUndefined();
});

test('an order with the auto-resolved office carries OUR point id and no postcode (captured, never placed)', async ({ page }) => {
    await openCheckoutAs(page, 'saved');
    await savedListShown(page);
    let captured = null;
    await page.route('**/api/checkout', async (route) => {
        captured = JSON.parse(route.request().postData());
        await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'x', errors: { customer_note: ['x'] } }) });
    });
    expect(await pickMethod(page, 'Еконт', 'office')).toBe(true);
    await expect(offices(page).first()).toBeVisible({ timeout: 20000 });
    await offices(page).first().check();
    await expect(page.locator('[data-sf-picker-summary]')).toBeVisible();
    await page.locator('input[name="payment_method"]').first().check();
    if (await page.locator('input[name="terms_accepted"]').count()) await page.locator('input[name="terms_accepted"]').check();
    await expect(page.locator('[data-sf-place-order]')).toBeEnabled({ timeout: 20000 });
    await page.locator('[data-sf-place-order]').click();
    await expect.poll(() => captured, { timeout: 15000 }).not.toBeNull();
    expect(captured.delivery_type).toBe('pickup_point');
    expect(captured.pickup_point_id).toMatch(/^\d+$/);
    expect(captured.settlement).toBe('Харманли');
    expect('postal_code' in captured).toBe(false);
    expect('address_id' in captured).toBe(false);
    expect('pickup_point_address' in captured).toBe(false);
});
