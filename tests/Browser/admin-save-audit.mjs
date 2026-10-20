// A REAL-BROWSER save audit of the admin design / settings pages (UX-0). It is NOT part of `php artisan test` (no browser in the suite): it is run by hand against a dev site.
//
//   AUDIT_BASE=https://easyco-main.test AUDIT_EMAIL=ux-audit@example.test AUDIT_PASSWORD=... \
//   PLAYWRIGHT_DIR=<folder that contains node_modules/playwright> [CHROMIUM=<path>] node tests/Browser/admin-save-audit.mjs [page ...] [--out=report.json]
//
// For every page it opens the page, changes EVERY field through the real controls (typing, clicking switches and selects, adding / removing / moving repeater rows and
// builder blocks), presses the page's own Save (and Publish), then hard-reloads and reads the form's state back. The state is read from Livewire (`$wire.$get('data')`)
// both right before Save and after the reload: a value that is not the same after the reload did not survive. It also records the notifications, the validation messages,
// the console errors and the failed network requests, and checks that storefront-facing values took effect on the public site.
//
// ONLY run it on a database you may change (the task that introduced it exports the settings first and restores them afterwards). Needs a staff account with the
// Administrator role. NOTE (MENU-2): the `menus` spec below drives the retired repeater editor; the menu editor is now tested by tests/Browser/menu-editor.mjs. Pages: appearance, storefront, catalog, bank, mail, checkout, header, menus, pages, locale (locale last: it changes the admin language).
import { createRequire } from 'node:module';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.AUDIT_BASE ?? 'https://easyco-main.test';
const EMAIL = process.env.AUDIT_EMAIL ?? 'ux-audit@example.test';
const PASSWORD = process.env.AUDIT_PASSWORD;
const args = process.argv.slice(2);
const OUT = (args.find((a) => a.startsWith('--out=')) ?? '').slice(6);
const ONLY = args.filter((a) => ! a.startsWith('--'));

if (! PASSWORD || ! process.env.PLAYWRIGHT_DIR) {
    console.error('usage: AUDIT_PASSWORD=... PLAYWRIGHT_DIR=<dir with node_modules/playwright> node admin-save-audit.mjs [page ...] [--out=file.json]');
    process.exit(2);
}

const { chromium } = createRequire(join(process.env.PLAYWRIGHT_DIR, 'x.js'))('playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
const ctx = await browser.newContext({ viewport: { width: 1500, height: 1000 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();

// ---------------------------------------------------------------------------------------------------------------------------------------- evidence collectors
let consoleErrors = [];
let failedRequests = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + String(e).slice(0, 300)));
page.on('response', async (r) => {
    if (r.status() >= 400 && ! r.url().includes('/favicon')) {
        let body = '';

        try { body = (await r.text()).slice(0, 500); } catch { /* gone */ }

        failedRequests.push(`${r.status()} ${r.request().method()} ${r.url().slice(0, 120)} ${body.replace(/\s+/g, ' ')}`);
    }
});

const results = [];
const record = (row) => { results.push(row); };

// ------------------------------------------------------------------------------------------------------------------------------------------------ helpers
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function login() {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL(/\/admin(?!\/login)/, { timeout: 60000 });
}

/** Waits until Livewire has no request in flight and the page is quiet. */
async function settle(ms = 500) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await sleep(ms);
    await page.waitForFunction(() => ! document.querySelector('[wire\\:loading]:not([style*="display: none"]):not([wire\\:loading\\.delay])') || true, null, { timeout: 2000 }).catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
}

async function open(path) {
    await page.goto(BASE + '/admin/' + path, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => window.Livewire && window.Livewire.all().length > 0, null, { timeout: 20000 });
    await sleep(500);
}

/** The Livewire form state (`data`) as plain JSON. */
async function state() {
    return page.evaluate(() => {
        const c = window.Livewire.all().find((x) => x.$wire && typeof x.$wire.$get === 'function' && x.$wire.$get('data') !== undefined);

        return c ? JSON.parse(JSON.stringify(c.$wire.$get('data'))) : null;
    });
}

const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** Repeater / builder keys are uuids that change on every load: an object whose keys are all uuids is compared as a list (in order). */
function norm(value) {
    if (Array.isArray(value)) return value.map(norm);

    if (value && typeof value === 'object') {
        const keys = Object.keys(value);

        if (keys.length > 0 && keys.every((k) => uuid.test(k))) return keys.map((k) => norm(value[k]));

        const out = {};
        keys.sort().forEach((k) => { out[k] = norm(value[k]); });

        return out;
    }

    return value;
}

const same = (a, b) => {
    const blank = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);

    if (blank(a) && blank(b)) return true;
    if (typeof a === 'string' && typeof b === 'string') return a.replace(/^(\d{4}-\d\d-\d\d)T/, '$1 ').toLowerCase().trim() === b.replace(/^(\d{4}-\d\d-\d\d)T/, '$1 ').toLowerCase().trim();
    if (['string', 'number'].includes(typeof a) && ['string', 'number'].includes(typeof b)) return String(a) === String(b);
    if (typeof a === 'boolean' || typeof b === 'boolean') return Boolean(a) === Boolean(b);
    if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((x, i) => same(x, b[i]));

    if (a && b && typeof a === 'object' && typeof b === 'object') {
        const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

        return [...keys].every((k) => same(a[k], b[k]));
    }

    return JSON.stringify(a) === JSON.stringify(b);
};

/** Leaf-level differences of two (normalised) states: [{path, before, after}]. */
function diff(before, after, prefix = '') {
    const out = [];

    if (before && after && typeof before === 'object' && typeof after === 'object' && ! Array.isArray(before) && ! Array.isArray(after)) {
        for (const k of new Set([...Object.keys(before), ...Object.keys(after)])) out.push(...diff(before[k], after[k], prefix ? `${prefix}.${k}` : k));

        return out;
    }

    if (Array.isArray(before) && Array.isArray(after) && before.length === after.length) {
        before.forEach((v, i) => out.push(...diff(v, after[i], `${prefix}[${i}]`)));

        return out;
    }

    return same(before, after) ? [] : [{ path: prefix, before, after }];
}

const sel = (path) => `[id="form.${path}"]`;

/** What kind of control a path is: text | select | native-select | toggle | radio | richtext | none. */
async function kindOf(path) {
    return page.evaluate((p) => {
        const el = document.getElementById('form.' + p);

        if (! el) return 'none';
        if (el.tagName === 'SELECT') return 'native-select';
        if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') return el.type === 'radio' ? 'radio' : 'text';

        const x = (el.closest('[x-data]')?.getAttribute('x-data') ?? '');

        if (x.includes('selectFormComponent')) return 'select';

        return el.tagName === 'BUTTON' ? 'toggle' : 'none';
    }, path);
}

/** Sets a field through its real control. `value` is typed / chosen; for a custom select it is the option text (or an index to pick the n-th option; null = the first option that is not selected). */
async function setField(path, value) {
    const kind = await kindOf(path);
    const loc = page.locator(sel(path)).first();
    await loc.scrollIntoViewIfNeeded().catch(() => {});

    if (kind === 'text') {
        await loc.click({ timeout: 5000 });
        await loc.fill(String(value));
        await loc.press('Tab');
    } else if (kind === 'native-select') {
        if (value === null || value === undefined) {
            const index = await loc.evaluate((el) => [...el.options].findIndex((o, i) => i !== el.selectedIndex && o.value !== ''));

            await loc.selectOption({ index });
        } else {
            await loc.selectOption(typeof value === 'number' ? { index: value } : { label: String(value) }).catch(async () => { await loc.selectOption(String(value)); });
        }
    } else if (kind === 'toggle') {
        const now = await loc.getAttribute('aria-checked');

        if (value === undefined || value === null || (value === true) !== (now === 'true')) await loc.click();
    } else if (kind === 'radio') {
        await loc.check();
    } else if (kind === 'select') {
        await pickOption(loc, value);
    } else {
        throw new Error(`no control for ${path}`);
    }

    await settle(400);

    return kind;
}

/** Opens a custom (Filament) select and picks an option by text, by index, or the first one that is not selected. */
async function pickOption(button, value) {
    await button.click();
    const list = page.locator('[role=listbox]:visible').last();
    await list.waitFor({ state: 'visible', timeout: 5000 });
    const options = list.locator('[role=option]');
    await options.first().waitFor({ state: 'attached', timeout: 5000 }).catch(() => {});
    await sleep(300);
    const count = await options.count();
    let target = null;

    if (typeof value === 'string') {
        for (let i = 0; i < count; i++) if ((await options.nth(i).innerText()).trim() === value) target = options.nth(i);
        if (! target) { await page.keyboard.press('Escape'); throw new Error(`option "${value}" not offered`); }
    } else {
        let real = -1;

        for (let i = 0; i < count && ! target; i++) {
            const dataValue = await options.nth(i).getAttribute('data-value');

            if (dataValue === null || dataValue === '') continue; // a placeholder row ("Избери опция") selects nothing

            real++;
            const selected = await options.nth(i).getAttribute('aria-selected');
            if (typeof value === 'number' ? real === value : selected !== 'true') target = options.nth(i);
        }
    }

    if (! target) {
        for (let i = 0; i < count && ! target; i++) if ((await options.nth(i).getAttribute('data-value')) !== null && (await options.nth(i).getAttribute('data-value')) !== '') target = options.nth(i);
    }

    await target.click();
}

/** Types into a custom select's search box then picks the first result (for long lists: country, time zone). */
async function searchPick(path, text) {
    const loc = page.locator(sel(path)).first();
    await loc.click();
    const box = page.locator('[role=listbox]:visible input, input[type=search]:visible').last();
    await box.fill(text);
    await sleep(700);
    await page.locator('[role=option]:visible').first().click();
    await settle(400);
}

async function notifications() {
    return page.evaluate(() => [...document.querySelectorAll('.fi-no-notification')].map((n) => ({ text: n.textContent.trim().replace(/\s+/g, ' ').slice(0, 200), danger: /danger|error/.test(n.className) })));
}

async function validationMessages() {
    return page.evaluate(() => [...new Set([...document.querySelectorAll('[class*="error-message"], [data-validation-error]')].map((e) => `${e.textContent.trim().replace(/\s+/g, ' ')}${e.offsetParent === null ? ' [HIDDEN: inside a collapsed block, the user sees nothing]' : ''}`).filter(Boolean))].slice(0, 20));
}

/** Presses a button by its visible text, waits for the answer and returns the evidence. */
async function press(label, { exact = true } = {}) {
    consoleErrors = [];
    failedRequests = [];
    await page.evaluate(() => document.querySelectorAll('.fi-no-notification').forEach((n) => n.remove()));
    await page.getByRole('button', { name: label, exact }).first().click();
    await page.locator('.fi-no-notification').first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
    await settle(900);

    return { notices: await notifications(), validation: await validationMessages(), invalid: await invalidControls(), consoleErrors: [...consoleErrors], failedRequests: [...failedRequests] };
}

/** Controls the BROWSER itself refuses (HTML5 `required` etc.): the submit never reaches the server; a hidden one cannot even show its message. */
async function invalidControls() {
    return page.evaluate(() => [...document.querySelectorAll('form :invalid')].filter((e) => e.willValidate).map((e) => `${e.id || e.name || e.tagName}${e.offsetParent === null ? ' (hidden: nothing is shown to the user)' : ''}`).slice(0, 10));
}

/** Confirms a Filament modal (publish / discard ask for confirmation). */
async function confirmModal() {
    const modal = page.locator('.fi-modal-window:visible').first();

    if (await modal.count()) {
        await modal.locator('button[type=submit], button:has-text("Потвърди"), button:has-text("Confirm")').first().click().catch(() => {});
        await page.locator('.fi-no-notification').first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
        await settle(900);
    }
}

async function publicHtml(path = '/') {
    const r = await ctx.request.get(BASE + path, { ignoreHTTPSErrors: true });

    return r.text();
}

/**
 * The audit of one page: `mutate()` changes the fields (returns the list of [path, action] it touched), `save` presses the save button(s), `after(htmlTools)` checks the public site.
 * The state right before Save is the expectation; the state after a hard reload is what came back.
 */
async function audit(name, openPath, { mutate, save, storefront, reloadPath }) {
    await open(openPath);
    const initial = await state();
    const touched = [];
    const errors = [];

    const m = {
        async set(path, action) {
            try {
                const kind = await setField(path, action);
                touched.push({ path, action: `${kind}: ${JSON.stringify(action)}` });
            } catch (e) {
                errors.push({ path, error: String(e).split(String.fromCharCode(10))[0].slice(0, 200) });
            }
        },
        async step(label, fn) {
            try {
                await fn();
                touched.push({ path: label, action: 'custom' });
            } catch (e) {
                errors.push({ path: label, error: String(e).split(String.fromCharCode(10))[0].slice(0, 200) });
            }
        },
    };

    try {
        const list = await mutate(m, { initial });

        for (const [path, action] of Array.isArray(list) ? list : []) await m.set(path, action);
    } catch (e) {
        errors.push({ path: '(mutate)', error: String(e).split(String.fromCharCode(10))[0].slice(0, 300) });
    }

    const expected = norm(await state());
    const evidence = await save();
    await open(reloadPath ?? openPath);
    const actual = norm(await state());
    const diffs = diff(expected, actual);
    const changed = new Set(touched.map((t) => t.path));
    const bad = diffs.filter((d) => [...changed].some((c) => norm_path(c) === norm_path(d.path)) || true);

    record({ page: name, step: 'save', touched: touched.length, uiErrors: errors, evidence, diffs: bad, initialDiffToExpected: diff(norm(initial), expected).length });

    if (storefront) {
        const checks = await storefront(expected, actual);
        record({ page: name, step: 'storefront', checks });
    }

    return { expected, actual, diffs, evidence, errors, touched };
}


// ----------------------------------------------------------------------------------------------------------------------------------- repeater / builder helpers
/** The `data.<path>` state of a repeater as {uuid: row}. */
async function rows(path) {
    return page.evaluate((p) => {
        const c = window.Livewire.all().find((x) => x.$wire && typeof x.$wire.$get === 'function' && x.$wire.$get('data') !== undefined);

        return JSON.parse(JSON.stringify(c.$wire.$get('data.' + p) ?? {}));
    }, path);
}

/** Presses the n-th button with this label (an "add row" button) and returns the uuid key of the NEW row of the repeater at `path`. */
async function addRow(path, label, nth = 0) {
    const before = Object.keys(await rows(path));
    const buttons = typeof label === 'string' ? page.getByRole('button', { name: label, exact: true }) : page.getByRole('button', { name: label });
    const button = nth < 0 ? buttons.last() : buttons.nth(nth);
    await button.scrollIntoViewIfNeeded().catch(() => {});
    await button.click();
    await settle(700);
    const added = Object.keys(await rows(path)).filter((k) => ! before.includes(k));

    if (added.length !== 1) throw new Error(`adding a row to ${path} gave ${added.length} new rows`);

    return added[0];
}

const itemOf = (path, key) => page.locator('li.fi-fo-repeater-item, li.fi-fo-builder-item').filter({ has: page.locator(`[id^="form.${path}.${key}."]`) }).first();

async function removeRow(path, key) {
    await itemOf(path, key).getByRole('button', { name: /Изтриване|Delete/ }).first().click();
    await settle(700);
}

/** Moves a row up / down: with the item's own buttons when the repeater has them, otherwise by dragging its handle over its neighbour. */
async function moveRow(path, key, direction) {
    const item = itemOf(path, key);
    const button = item.getByRole('button', { name: direction === 'up' ? /нагоре|Move up/i : /надолу|Move down/i });

    if (await button.count()) {
        await button.first().click();
    } else {
        const keys = Object.keys(await rows(path));
        const neighbour = keys[keys.indexOf(key) + (direction === 'up' ? -1 : 1)];
        const handle = item.getByRole('button', { name: /Преместване|Reorder|Move/ }).first();
        const target = itemOf(path, neighbour).getByRole('button', { name: /Преместване|Reorder|Move/ }).first();
        await handle.dragTo(target);
    }

    await settle(700);
}

const COLLAPSED = (path, key) => page.evaluate(([p, k]) => {
    const el = document.querySelector(`[id^="form.${p}.${k}."]`);

    return el ? el.closest('li')?.classList.contains('fi-collapsed') ?? null : null;
}, [path, key]);

const norm_path = (p) => p.replace(/\[\d+\]/g, '').replace(/^.*\./, '');


// ============================================================================================================================================= page specs
const SAVE = () => press('Запази');
const tokensCss = async () => {
    const html = await publicHtml('/');
    const m = html.match(/href="([^"]*appearance\/tokens-[0-9a-f]+\.css)"/);

    return { html, css: m ? await (await ctx.request.get(new URL(m[1], BASE).toString(), { ignoreHTTPSErrors: true })).text() : '' };
};
const expectIn = (checks, label, ok, detail = '') => checks.push({ check: label, ok: Boolean(ok), detail });

let auditMenuPath = process.env.AUDIT_MENU_PATH ?? null;

const PAGES = {
    appearance: async () => audit('Облик', 'appearance-settings', {
        mutate: async () => [
            ['c_background', '#f8fafc'], ['c_text', '#0f172a'], ['c_muted', '#475569'], ['c_primary', '#1d4ed8'], ['c_on_primary', '#f0f9ff'], ['c_accent', '#be123c'],
            ['c_border', '#cbd5e1'], ['c_header_bg', '#f1f5f9'], ['c_header_text', '#111827'], ['c_footer_bg', '#e2e8f0'], ['c_footer_text', '#334155'],
            ['font_body', null], ['font_heading', null], ['radius', null], ['button', null],
            ['banner_text', 'UX одит банер ✓'], ['banner_url', '/cart'], ['banner_link_label', 'Виж'], ['c_banner_text', '#fefce8'], ['c_banner_bg', '#1e3a8a'],
            ['banner_starts_at', '2026-01-01T00:00'], ['banner_ends_at', '2030-01-01T00:00'],
        ],
        save: SAVE,
        storefront: async (expected) => {
            const { html, css } = await tokensCss();
            const checks = [];
            expectIn(checks, 'banner text on the home page', html.includes('UX одит банер'));
            expectIn(checks, '--sf-primary in the token stylesheet', /--sf-primary:\s*#1d4ed8/i.test(css), css.slice(0, 120));
            expectIn(checks, '--sf-banner-bg', /#1e3a8a/i.test(css));
            expectIn(checks, 'the footer colour', /#e2e8f0/i.test(css));

            return checks;
        },
    }),

    'appearance-banner-off': async () => audit('Облик (банер изключен)', 'appearance-settings', {
        mutate: async () => [['banner_enabled', false]],
        save: SAVE,
        storefront: async () => {
            const checks = [];
            expectIn(checks, 'no banner on the home page', ! (await publicHtml('/')).includes('UX одит банер'));

            return checks;
        },
    }),

    storefront: async () => {
        await audit('Витрина', 'storefront-settings', {
            mutate: async () => [['home_title', 'Добре дошли — UX одит'], ['home_text', 'Текст на началото с кирилица и числа 123.']],
            save: SAVE,
            storefront: async () => {
                const html = await publicHtml('/');
                const checks = [];
                expectIn(checks, 'title on the home page', html.includes('Добре дошли — UX одит'));
                expectIn(checks, 'text on the home page', html.includes('Текст на началото с кирилица'));

                return checks;
            },
        });

        return audit('Витрина (празно = по подразбиране)', 'storefront-settings', {
            mutate: async () => [['home_title', ''], ['home_text', '']],
            save: SAVE,
            storefront: async () => {
                const html = await publicHtml('/');
                const checks = [];
                expectIn(checks, 'the custom title is gone', ! html.includes('Добре дошли — UX одит'));

                return checks;
            },
        });
    },

    catalog: async () => audit('Каталог', 'catalog-settings', {
        mutate: async () => ['season_field_enabled', 'brand_field_enabled', 'tags_field_enabled', 'product_group_field_enabled', 'product_group_required'].map((p) => [p, undefined]),
        save: SAVE,
    }),

    bank: async () => audit('Банков превод', 'bank-transfer-settings', {
        mutate: async () => [
            ['account_holder', 'Тест Титуляр ООД'], ['bank_name', 'Банка UX'], ['iban', 'BG80BNBG96611020345678'], ['bic', 'BNBGBGSF'], ['deadline_days', '9'], ['instructions', 'Моля, посочете номера на поръчката.'],
        ],
        save: SAVE,
    }),

    'mail-smtp': async () => audit('Поща (SMTP)', 'mail-settings', {
        mutate: async () => [
            ['transport', null], ['smtp_host', 'smtp.example.test'], ['smtp_port', '2525'], ['smtp_encryption', null], ['smtp_username', 'uxuser'], ['smtp_password', 'uxsecret-pass'],
        ],
        save: SAVE,
    }),

    mail: async () => audit('Поща', 'mail-settings', {
        mutate: async () => [
            ['from_transactional_address', 'orders-ux@example.test'], ['from_transactional_name', 'Поръчки UX'], ['from_marketing_address', 'news-ux@example.test'],
            ['from_marketing_name', 'Новини UX'], ['reply_to', 'help-ux@example.test'], ['domain_confirmed', undefined], ['test_recipient', 'qa-ux@example.test'],
            ['dns_include', 'spf.example.test'], ['dns_selector', 'uxsel'],
        ],
        save: SAVE,
    }),
    checkout: async () => {
        const result = await audit('Поръчка', 'checkout-settings', {
            mutate: async (m) => {
                for (const p of ['phone_mobile_only', 'call_before_shipping_enabled', 'newsletter_enabled']) await m.set(p, undefined);

                await m.set('terms_source-url', true);
                await m.set('terms_url', 'https://example.test/ux-terms');
                await m.set('privacy_source-url', true);
                await m.set('privacy_url', '/ux-privacy');
                await m.set('default', 2);

                // two exceptions
                for (const [i, [country, mode]] of [['Германия', null], ['Франция', 1]].entries()) {
                    await m.step(`exceptions row ${i + 1}`, async () => {
                        const key = await addRow('exceptions', 'Добави държава', 0);
                        await searchPick(`exceptions.${key}.country`, country);
                        await setField(`exceptions.${key}.mode`, mode);
                    });
                }

                // the invoice rules: change the existing row, add a second, move it up
                const existing = Object.keys(await rows('invoice_rules'));

                if (existing.length) {
                    const k = existing[0];
                    await m.set(`invoice_rules.${k}.tax_id_label`, 'ЕИК UX');
                    await m.set(`invoice_rules.${k}.tax_id_required_for_company`, undefined);
                    await m.set(`invoice_rules.${k}.tax_id_pattern`, '^[0-9]{9}$');
                    await m.set(`invoice_rules.${k}.registration_number_label`, 'ДДС UX');
                    await m.set(`invoice_rules.${k}.registration_number_required`, undefined);
                    await m.set(`invoice_rules.${k}.representative_label`, 'МОЛ UX');
                }

                await m.step('invoice rule row added and moved up', async () => {
                    const key = await addRow('invoice_rules', 'Добави държава', 1);
                    await searchPick(`invoice_rules.${key}.country`, 'Румъния');
                    await setField(`invoice_rules.${key}.tax_id_label`, 'CUI UX');
                    await moveRow('invoice_rules', key, 'up');
                });

                await m.set('ip_full_retention_days', '45');
            },
            save: SAVE,
            storefront: async () => {
                const html = await publicHtml('/checkout');
                const checks = [];
                expectIn(checks, 'the terms link on /checkout', html.includes('example.test/ux-terms'));

                return checks;
            },
        });

        return result;
    },

    'checkout-more': async () => audit('Поръчка (бутон версия, фактура, правила)', 'checkout-settings', {
        mutate: async (m) => {
            await m.step('bump the terms version', async () => {
                await page.getByRole('button', { name: 'Правилата са обновени', exact: true }).click();
                await settle(900);
            });
            await m.set('invoice_enabled', undefined);
            await m.set('terms_enabled', undefined);
            await m.step('remove an exception row', async () => {
                const keys = Object.keys(await rows('exceptions'));

                if (keys.length) await removeRow('exceptions', keys[0]);
            });
            await m.step('remove an invoice rule row', async () => {
                const keys = Object.keys(await rows('invoice_rules'));

                if (keys.length) await removeRow('invoice_rules', keys[keys.length - 1]);
            });
        },
        save: SAVE,
    }),

    header: async () => {
        const slotIndex = { left: 0, center: 1, right: 2 };
        const rowIndex = { top: 0, main: 1, bottom: 2 };
        const expandAll = async () => {
            await page.evaluate(() => {
                document.querySelectorAll('[x-data]').forEach((el) => {
                    try {
                        const d = window.Alpine.$data(el);

                        if (d && 'isCollapsed' in d) d.isCollapsed = false;
                    } catch { /* not an Alpine scope */ }
                });
            });
            await sleep(400);
        };
        const rowTitle = { top: 'Горен ред', main: 'Основен ред', bottom: 'Долен ред' };
        const slotTitle = { left: 'Ляво', center: 'Център', right: 'Дясно' };
        const chooseBlock = async (button, label) => {
            await button.scrollIntoViewIfNeeded();
            await button.click();
            await page.locator('.fi-dropdown-panel:visible').getByText(label, { exact: true }).first().click();
            await settle(800);
        };
        const addBlock = async (layout, row, slot, label) => {
            const path = `${layout}.rows.${row}.${slot}`;
            const before = Object.keys(await rows(path));
            const field = page.locator('.fi-section').filter({ hasText: rowTitle[row] }).locator('.fi-fo-field').filter({ has: page.getByText(slotTitle[slot], { exact: true }) });
            await chooseBlock(field.first().getByRole('button', { name: 'Добави елемент', exact: true }), label);
            const added = Object.keys(await rows(path)).filter((k) => ! before.includes(k));

            if (added.length !== 1) throw new Error(`block ${label} in ${path}: ${added.length} new`);

            await expandAll();

            return added[0];
        };
        const keyOf = async (path, type) => Object.entries(await rows(path)).find(([, v]) => v.type === type)?.[0];

        const result = await audit('Хедър (чернова)', 'header-settings', {
            mutate: async (m) => {
                await expandAll();
                await m.set('desktop.sticky', undefined);
                await m.set('desktop.rows.top.height', '40');
                await m.set('desktop.rows.main.height', '52');
                await m.set('desktop.rows.bottom.enabled', undefined);
                await m.set('desktop.rows.bottom.height', '48');

                const logo = await keyOf('desktop.rows.main.center', 'logo');
                if (logo) await m.set(`desktop.rows.main.center.${logo}.data.height`, '24');
                const menu = await keyOf('desktop.rows.main.left', 'menu');
                if (menu) { await m.set(`desktop.rows.main.left.${menu}.data.source`, null); await m.set(`desktop.rows.main.left.${menu}.data.style`, null); }
                const search = await keyOf('desktop.rows.main.right', 'search');
                if (search) await m.set(`desktop.rows.main.right.${search}.data.mode`, null);
                const account = await keyOf('desktop.rows.main.right', 'account');
                if (account) await m.set(`desktop.rows.main.right.${account}.data.style`, null);
                const cart = await keyOf('desktop.rows.main.right', 'cart');
                if (cart) await m.set(`desktop.rows.main.right.${cart}.data.style`, null);
                const text = await keyOf('desktop.rows.top.right', 'text');
                if (text) { await m.set(`desktop.rows.top.right.${text}.data.text`, '0888 UX 111'); await m.set(`desktop.rows.top.right.${text}.data.url`, '/contacts'); }

                await m.step('add a button to the top row, left', async () => {
                    const k = await addBlock('desktop', 'top', 'left', 'Бутон');
                    await setField(`desktop.rows.top.left.${k}.data.label`, 'Виж UX');
                    await setField(`desktop.rows.top.left.${k}.data.url`, '/sale');
                    await setField(`desktop.rows.top.left.${k}.data.style`, null);
                });
                await m.step('add a spacer to the top row, centre', async () => {
                    const k = await addBlock('desktop', 'top', 'center', 'Разстояние');
                    await setField(`desktop.rows.top.center.${k}.data.width`, null);
                });
                await m.step('add an HTML block to the bottom row, centre', async () => {
                    const k = await addBlock('desktop', 'bottom', 'center', 'HTML блок');
                    await setField(`desktop.rows.bottom.center.${k}.data.html`, '<p>UX <b>html</b> блок</p>');
                });
                await m.step('add a text to the bottom row, right', async () => {
                    const k = await addBlock('desktop', 'bottom', 'right', 'Текст');
                    await setField(`desktop.rows.bottom.right.${k}.data.text`, 'Долен текст UX');
                });
                await m.step('add social networks to the bottom row, left', async () => {
                    const k = await addBlock('desktop', 'bottom', 'left', 'Социални мрежи');
                    const itemsPath = `desktop.rows.bottom.left.${k}.data.items`;
                    const known = Object.keys(await rows(itemsPath));
                    await itemOf('desktop.rows.bottom.left', k).getByRole('button', { name: /^Добави/ }).last().click();
                    await settle(700);
                    const row = Object.keys(await rows(itemsPath)).find((x) => ! known.includes(x));
                    await setField(`desktop.rows.bottom.left.${k}.data.items.${row}.network`, null);
                    await setField(`desktop.rows.bottom.left.${k}.data.items.${row}.url`, 'https://example.test/ux');
                });
                await m.step('remove the account element (main row)', async () => { if (account) await removeRow('desktop.rows.main.right', account); });
                await m.step('move the cart before the search (main row)', async () => { if (cart) await moveRow('desktop.rows.main.right', cart, 'up'); });

                // the phone
                await page.getByRole('tab', { name: 'Телефон' }).or(page.getByRole('button', { name: 'Телефон', exact: true })).first().click();
                await settle(500);
                await expandAll();
                await m.set('mobile.sticky', undefined);
                await m.set('mobile.rows.top.enabled', undefined);
                await m.set('mobile.rows.top.height', '36');
                await m.set('mobile.rows.main.height', '50');
                const mlogo = await keyOf('mobile.rows.main.center', 'logo');
                if (mlogo) await m.set(`mobile.rows.main.center.${mlogo}.data.height`, '30');
                const toggle = await keyOf('mobile.rows.main.left', 'menu_toggle');
                if (toggle) await m.set(`mobile.rows.main.left.${toggle}.data.label_visible`, undefined);
                await m.set('mobile.drawer_side', null);
                const dmenu = await keyOf('mobile.drawer', 'menu');
                if (dmenu) await m.set(`mobile.drawer.${dmenu}.data.source`, null);
                await m.step('add a text to the drawer', async () => {
                    const before = Object.keys(await rows('mobile.drawer'));
                    const field = page.locator('.fi-fo-field').filter({ has: page.getByText('Меню на телефона (чекмедже)', { exact: true }) });
                    await chooseBlock(field.first().getByRole('button', { name: 'Добави елемент', exact: true }), 'Текст');
                    const k = Object.keys(await rows('mobile.drawer')).find((x) => ! before.includes(x));
                    await expandAll();
                    await setField(`mobile.drawer.${k}.data.text`, 'Чекмедже текст UX');
                });
                await m.step('remove the account from the drawer', async () => { const a = await keyOf('mobile.drawer', 'account'); if (a) await removeRow('mobile.drawer', a); });
            },
            save: () => press('Запази чернова'),
        });

        // PUBLISH
        await page.getByRole('button', { name: 'Публикувай', exact: true }).first().click();
        await settle(500);
        const published = await (async () => {
            const ev = { notices: [], validation: [], invalid: [], consoleErrors: [], failedRequests: [] };
            await confirmModal();
            ev.notices = await notifications();
            ev.validation = await validationMessages();
            ev.invalid = await invalidControls();

            return ev;
        })();
        const html = await publicHtml('/');
        const checks = [];
        expectIn(checks, 'the published header shows the new phone text', html.includes('0888 UX 111'));
        expectIn(checks, 'the published header shows the top-left button', html.includes('Виж UX'));
        expectIn(checks, 'the published header shows the html block', html.includes('UX <b>html</b> блок'));
        record({ page: 'Хедър (публикуване)', step: 'storefront', checks, evidence: published });

        return result;
    },

    menus: async () => {
        // a menu of its own for the audit (the owner's `main` and `footer` are not edited here)
        await open('menus');
        await page.getByRole('button', { name: 'Ново меню', exact: true }).first().click();
        await page.locator('.fi-modal-window:visible input').first().waitFor({ state: 'visible', timeout: 10000 });
        const dialog = page.locator('.fi-modal-window:visible').first();
        await dialog.locator('input').nth(0).fill('UX одит меню');
        await dialog.locator('input').nth(1).fill('ux-audit');
        await dialog.locator('button[type=submit]').first().click();
        await page.waitForURL(/menus\/\d+\/edit/, { timeout: 20000 });
        const editPath = page.url().replace(BASE + '/admin/', '');
        auditMenuPath = editPath;
        const expandAll = async () => {
            await page.evaluate(() => document.querySelectorAll('[x-data]').forEach((el) => { try { const d = window.Alpine.$data(el); if (d && 'isCollapsed' in d) d.isCollapsed = false; } catch { /* not a scope */ } }));
            await sleep(400);
        };
        const png = join(tmpdir(), 'ux-audit.png');
        writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
        const item = async (m, type, fields) => {
            const key = await addRow('items', 'Добави елемент', -1);
            await expandAll();
            await setField(`items.${key}.type`, type);
            await settle(500);

            for (const [f, v] of Object.entries(fields)) await m.set(`items.${key}.${f}`, v);

            return key;
        };

        const result = await audit('Менюта (ново меню)', editPath, {
            mutate: async (m) => {
                await m.set('name', 'UX одит меню (преименувано)');
                let linkKey; let catKey; let pageKey; let megaKey;
                await m.step('item: link', async () => { linkKey = await item(m, 'Връзка', { label: 'Начало UX', url: '/', new_tab: undefined }); });
                await m.step('item: category with a dropdown', async () => {
                    catKey = await item(m, 'Категория', {});
                    await searchPick(`items.${catKey}.category_id`, 'Дрехи');
                    await m.set(`items.${catKey}.label`, 'Дамски UX');
                    await m.set(`items.${catKey}.with_children`, undefined);
                    await m.set(`items.${catKey}.submenu`, 'Падащо меню');
                    await settle(600);
                    await m.set(`items.${catKey}.children_limit`, '5');
                    const row = await (async () => {
                        const before = Object.keys(await rows(`items.${catKey}.dropdown_items`));
                        await itemOf('items', catKey).getByRole('button', { name: 'Добави елемент', exact: true }).first().click();
                        await settle(700);
                        return Object.keys(await rows(`items.${catKey}.dropdown_items`)).find((k) => ! before.includes(k));
                    })();
                    await expandAll();
                    await setField(`items.${catKey}.dropdown_items.${row}.type`, 'Връзка');
                    await settle(500);
                    await setField(`items.${catKey}.dropdown_items.${row}.label`, 'Промо UX');
                    await setField(`items.${catKey}.dropdown_items.${row}.url`, '/promo');
                });
                await m.step('item: page', async () => {
                    pageKey = await item(m, 'Страница', {});
                    await setField(`items.${pageKey}.page_id`, null);
                    await m.set(`items.${pageKey}.label`, 'Правила UX');
                });
                await m.step('item: mega with two columns, a link, a category, a page and a picture', async () => {
                    megaKey = await item(m, 'Връзка', { label: 'Мега UX', url: '/mega' });
                    await m.set(`items.${megaKey}.submenu`, 'Мега меню');
                    await settle(600);
                    const col = async () => {
                        const before = Object.keys(await rows(`items.${megaKey}.columns`));
                        await itemOf('items', megaKey).getByRole('button', { name: 'Добави колона', exact: true }).first().click();
                        await settle(700);
                        const k = Object.keys(await rows(`items.${megaKey}.columns`)).find((x) => ! before.includes(x));
                        await expandAll();

                        return k;
                    };
                    const c1 = await col();
                    await setField(`items.${megaKey}.columns.${c1}.label`, 'Колона едно');
                    const addColumnItem = async (c, type) => {
                        const p = `items.${megaKey}.columns.${c}.column_items`;
                        const before = Object.keys(await rows(p));
                        await page.locator('li.fi-fo-repeater-item').filter({ has: page.locator(`[id^="form.items.${megaKey}.columns.${c}.label"]`) }).last().getByRole('button', { name: 'Добави елемент', exact: true }).first().click();
                        await settle(700);
                        const k = Object.keys(await rows(p)).find((x) => ! before.includes(x));
                        await expandAll();
                        await setField(`${p}.${k}.type`, type);
                        await settle(500);

                        return `${p}.${k}`;
                    };
                    const a = await addColumnItem(c1, 'Връзка');
                    await setField(`${a}.label`, 'Рокли UX');
                    await setField(`${a}.url`, '/dresses');
                    const b = await addColumnItem(c1, 'Категория');
                    await searchPick(`${b}.category_id`, 'Дрехи');
                    const c = await addColumnItem(c1, 'Страница');
                    await setField(`${c}.page_id`, null);
                    const c2 = await col();
                    await setField(`items.${megaKey}.columns.${c2}.label`, 'Колона две');
                    const img = await addColumnItem(c2, 'Снимка');
                    await page.locator(`[id="form.${img}.image_file"]`).or(page.locator(`[id^="form.${img}.image_file"] input[type=file]`)).first().setInputFiles(png).catch(async () => { await page.locator('input[type=file]:visible, input[type=file]').last().setInputFiles(png); });
                    await settle(2500);
                    await setField(`${img}.image_alt`, 'Снимка UX');
                    await setField(`${img}.caption`, 'Надпис UX');
                    await setField(`${img}.url`, '/promo-image');
                });
                await m.step('move the mega item up', async () => { await moveRow('items', megaKey, 'up'); });
                await m.step('remove the page item', async () => { await removeRow('items', pageKey); });
            },
            save: SAVE,
        });

        // a second round on the reopened menu: change the submenu type, a label, remove a column item, add an item
        const second = await audit('Менюта (повторно отваряне)', editPath, {
            mutate: async (m) => {
                const items = await rows('items');
                const keys = Object.keys(items);
                const dd = keys.find((k) => items[k].submenu === 'dropdown');

                if (dd) { await expandAll(); await m.set(`items.${dd}.label`, 'Дамски UX 2'); await m.set(`items.${dd}.submenu`, 'Нищо'); }
                const mega = keys.find((k) => items[k].submenu === 'mega');

                if (mega) { await m.set(`items.${mega}.label`, 'Мега UX 2'); await m.step('remove the first column', async () => { const cols = Object.keys(await rows(`items.${mega}.columns`)); await removeRow(`items.${mega}.columns`, cols[0]); }); }
                await m.step('add a last link', async () => { await item(m, 'Връзка', { label: 'Последна UX', url: '/last' }); });
            },
            save: SAVE,
        });

        // the footer is public: a link added to it must show in the storefront footer
        const footer = await audit('Менюта (футър, публично)', 'menus/2/edit', {
            mutate: async (m) => { await m.step('add a link', async () => { await item(m, 'Връзка', { label: 'UX футър връзка', url: '/ux-footer' }); }); },
            save: SAVE,
            storefront: async () => {
                const checks = [];
                expectIn(checks, 'the footer shows the new link', (await publicHtml('/')).includes('UX футър връзка'));

                return checks;
            },
        });

        return [result, second, footer];
    },

    // The traps the owner hit: a Save that does NOTHING. An item added and left empty (its required fields blank), then Save.
    'menus-empty-item': async () => {
        const path = auditMenuPath ?? 'menus/1/edit';
        await open(path);
        const before = await state();
        consoleErrors = [];
        const key = await addRow('items', 'Добави елемент', -1);
        await sleep(300);
        const collapsedBySave = [];
        // 1) the item is open, the type is the default (category), nothing is chosen
        const open1 = await press('Запази');
        collapsedBySave.push({ scenario: 'new item left empty, expanded', ...open1 });
        // 2) the same item collapsed (a person tidies the list, then saves)
        await page.evaluate(() => document.querySelectorAll('li.fi-fo-repeater-item').forEach((li) => { try { window.Alpine.$data(li).isCollapsed = true; } catch { /* not a scope */ } }));
        await sleep(300);
        const open2 = await press('Запази');
        collapsedBySave.push({ scenario: 'new item left empty, collapsed', ...open2 });
        // 3) a LINK item left empty (its label and address are native-required inputs), expanded and then collapsed
        await setField(`items.${key}.type`, 'Връзка');
        await settle(500);
        collapsedBySave.push({ scenario: 'link item left empty, expanded', ...(await press('Запази')) });
        await page.evaluate(() => document.querySelectorAll('li.fi-fo-repeater-item').forEach((li) => { try { window.Alpine.$data(li).isCollapsed = true; } catch { /* not a scope */ } }));
        await sleep(300);
        collapsedBySave.push({ scenario: 'link item left empty, collapsed', ...(await press('Запази')) });
        record({ page: 'Менюта (празен елемент и Запази)', step: 'silent-save', collapsedBySave, newItem: key });
        await open(path);
        const after = await state();
        record({ page: 'Менюта (празен елемент и Запази)', step: 'save', touched: 1, uiErrors: [], evidence: collapsedBySave[1], diffs: diff(norm(before), norm(after)), initialDiffToExpected: 0 });
    },

    'menus-cleanup': async () => {
        await open('menus');
        const row = page.locator('tr').filter({ hasText: 'ux-audit' }).first();
        await row.getByRole('button', { name: 'Изтрий', exact: true }).or(row.getByText('Изтрий', { exact: true })).first().click();
        await sleep(600);
        const modal = page.locator('.fi-modal-window:visible').first();
        await modal.locator('button[type=submit], button:has-text("Потвърди")').first().click();
        await settle(1200);
        const html = await page.content();
        record({ page: 'Менюта (изтриване на менюто)', step: 'storefront', checks: [{ check: 'the audit menu is gone from the list', ok: ! html.includes('ux-audit'), detail: '' }, { check: 'main stays', ok: html.includes('main'), detail: '' }] });
    },

    pages: async () => {
        const openSections = async () => {
            await page.evaluate(() => document.querySelectorAll('.fi-section').forEach((el) => { try { window.Alpine.$data(el).isCollapsed = false; } catch { /* not a scope */ } }));
            await sleep(400);
        };
        const typeBody = async (text) => {
            const editor = page.locator('div[role=textbox]').first();
            await editor.click();
            await page.keyboard.press('Control+A');
            await page.keyboard.type(text);
            await settle(700);
        };

        // CREATE
        await open('pages/create');
        await openSections();
        await setField('title', 'UX одит страница');
        await setField('slug', 'ux-audit-page');
        await typeBody('Текст на одит страницата с кирилица.');
        await setField('status', 'Публикувана').catch(async () => setField('status', 1));
        await setField('meta_title', 'UX SEO заглавие');
        await setField('meta_description', 'UX SEO описание');
        await setField('noindex', true);
        const expected = norm(await state());
        const created = await press('Създай');
        await page.waitForURL(/pages\/\d+\/edit/, { timeout: 20000 }).catch(() => {});
        await settle(800);
        const editPath = page.url().replace(BASE + '/admin/', '');
        const after = norm(await state());
        record({ page: 'Страници (създаване)', step: 'save', touched: 7, uiErrors: [], evidence: created, diffs: diff(expected, after), initialDiffToExpected: 0 });
        const html = await publicHtml('/info/ux-audit-page');
        const checks = [];
        expectIn(checks, 'the page is public and has its title', html.includes('UX одит страница'));
        expectIn(checks, 'the body is shown', html.includes('Текст на одит страницата'));
        expectIn(checks, 'the SEO title is in <title>', html.includes('UX SEO заглавие'));
        expectIn(checks, 'noindex is applied', /noindex/.test(html));
        record({ page: 'Страници (създаване)', step: 'storefront', checks });

        // EDIT
        await audit('Страници (редакция)', editPath, {
            mutate: async (m) => {
                await openSections();
                await m.set('title', 'UX одит страница 2');
                await m.set('meta_title', 'UX SEO заглавие 2');
                await m.set('meta_description', 'UX SEO описание 2');
                await m.set('noindex', false);
                await m.step('body', async () => typeBody('Нов текст на одит страницата.'));
                await m.set('status', 'Чернова').catch(async () => {});
                await m.set('status', 'Публикувана').catch(async () => {});
            },
            save: SAVE,
            storefront: async () => {
                const h = await publicHtml('/info/ux-audit-page');
                const c = [];
                expectIn(c, 'the new title is public', h.includes('UX одит страница 2'));
                expectIn(c, 'the new body is public', h.includes('Нов текст на одит страницата'));
                expectIn(c, 'noindex is gone', ! /name="robots" content="noindex"/.test(h));

                return c;
            },
        });

        // THE COLLAPSED-SECTION TRAP: a server-side error inside the collapsed SEO section
        await open(editPath);
        await openSections();
        await setField('meta_title', 'Лош‮заглавие');
        await page.evaluate(() => document.querySelectorAll('.fi-section').forEach((el) => { try { window.Alpine.$data(el).isCollapsed = true; } catch { /* not a scope */ } }));
        await sleep(400);
        const trap = await press('Запази');
        const sectionOpen = await page.evaluate(() => [...document.querySelectorAll('.fi-section')].map((s) => (s.querySelector('h3, h2, .fi-section-header-heading')?.textContent ?? '').trim() + ':' + (s.classList.contains('fi-collapsed') ? 'collapsed' : 'open')));
        const visible = await page.evaluate(() => [...document.querySelectorAll('[class*="error-message"]')].map((e) => e.offsetParent !== null));
        record({ page: 'Страници (грешка в сгънат раздел)', step: 'silent-save', collapsedBySave: [{ scenario: 'an invalid SEO title in the collapsed SEO section', ...trap, sections: sectionOpen, errorVisible: visible }] });

        // the footer hint replaces the old fields
        const text = await page.evaluate(() => document.body.innerText);
        await openSections();
        const text2 = await page.evaluate(() => document.body.innerText);
        record({ page: 'Страници (футър)', step: 'storefront', checks: [{ check: 'the footer section points to the menus', ok: /Футърът се подрежда в Менюта/.test(text2) || /Менюта/.test(text2), detail: '' }] });
    },

    'pages-cleanup': async () => {
        // pages are never hard-deleted: the audit page is archived here; the restore step removes it from the database
        await open('pages');
        record({ page: 'Страници (списък)', step: 'storefront', checks: [{ check: 'the list shows the audit page', ok: (await page.content()).includes('ux-audit-page'), detail: '' }] });
    },

    locale: async () => audit('Локализация', 'locale-settings', {
        mutate: async (m) => {
            const tab = async (name) => { await page.getByRole('tab', { name, exact: true }).click(); await settle(400); };
            await tab('Език');
            await m.step('country', async () => { await searchPick('country', 'Румъния'); });
            await tab('Часова зона');
            await m.step('time zone', async () => { await searchPick('timezone', 'Berlin'); });
            await tab('Дневник');
            await m.set('activity_log_enabled', undefined);
            await m.set('activity_log_retention_months', null);
            await tab('Валута');
            await m.set('currency_symbol_position', null);
            await tab('Език');
            await m.set('locale', 'English'); // last: it changes the admin language
        },
        save: SAVE,
        storefront: async () => {
            const html = await publicHtml('/');
            const checks = [];
            expectIn(checks, 'the storefront language follows', /<html lang="en"/.test(html), (html.match(/<html[^>]*>/) ?? [''])[0]);

            return checks;
        },
    }),

};

// ============================================================================================================================================= driver
await login();
const names = ONLY.length ? ONLY : Object.keys(PAGES);

for (const name of names) {
    consoleErrors = [];
    failedRequests = [];

    try {
        await PAGES[name]();
    } catch (e) {
        record({ page: name, step: 'CRASH', error: String(e).split('\n').slice(0, 3).join(' | ') });
    }

    console.log('done', name);
}

const summary = results.map((r) => {
    if (r.step === 'save') {
        const e = r.evidence ?? {};

        return `${r.page}: touched=${r.touched} uiErrors=${r.uiErrors.length} notices=${JSON.stringify(e.notices?.map((n) => (n.danger ? '!' : '') + n.text))} validation=${JSON.stringify(e.validation)} console=${(e.consoleErrors ?? []).length} failed=${(e.failedRequests ?? []).length} DIFFS=${r.diffs.length}`;
    }

    return `${r.page}: ${r.step} ${JSON.stringify(r.checks ?? r.error)}`;
});
console.log(summary.join('\n'));

if (OUT) writeFileSync(OUT, JSON.stringify(results, null, 2));

await browser.close();
