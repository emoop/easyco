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

async function loginCustomer(page) {
    await page.goto('/');
    await page.request.get('/sanctum/csrf-cookie');
    const cookies = await page.context().cookies();
    const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const response = await page.request.post('/api/account/login', {
        headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', Referer: page.url() },
        data: { email: users.customer.email, password: users.customer.password },
    });
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
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/cart/lines') && r.request().method() === 'POST'),
        box.locator('.sf-add-to-cart').click(),
    ]);
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
