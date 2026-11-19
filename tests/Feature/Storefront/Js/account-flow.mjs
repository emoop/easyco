// Behavioural check of the storefront ACCOUNT scripts (resources/js/storefront-account.js, ACC-3b-2) without a browser and without a new dependency: a REAL rendered
// account page (the address book, the address form, the details page) is parsed into a tiny fake DOM (the same one cart-flow.mjs uses) and the REAL script runs
// against it with a scripted fetch (storefront-cart.js api() is the real one: the shared CSRF flow).
//
//   node account-flow.mjs <rendered-page.html> <scenario>
//
// Exit code 0 and the line "OK <scenario>" when every assertion holds. Run by tests/Feature/Storefront/StorefrontAccountFlowTest.php (skipped without node).
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const VOID = new Set(['meta', 'link', 'input', 'br', 'img', 'hr', 'source']);
const BOOLEAN = ['hidden', 'checked', 'disabled', 'required'];

class FakeNode {
    constructor(tag) {
        this.tag = tag;
        this.children = [];
        this.parent = null;
        this.attrs = {};
        this.listeners = {};
        this.data = '';
        this.hidden = false;
        this.checked = false;
        this.disabled = false;
        this.required = false;
        this._value = undefined;
        this.rect = null;
        this.style = { props: {}, setProperty(k, v) { this.props[k] = String(v); }, removeProperty(k) { delete this.props[k]; }, getPropertyValue(k) { return this.props[k] ?? ''; } };
    }

    get id() { return this.attrs.id ?? ''; }
    get name() { return this.attrs.name ?? ''; }
    get firstChild() { return this.children[0] ?? null; }
    get options() { return this.tag === 'select' ? this.children.filter((c) => c.tag === 'option') : undefined; }
    get value() {
        if (this._value === undefined && this.tag === 'select') {
            const option = this.children.find((c) => 'selected' in c.attrs) ?? this.children[0];

            return option ? (option.attrs.value ?? option.textContent) : '';
        }

        return this._value ?? this.attrs.value ?? '';
    }
    set value(v) { this._value = String(v); }
    set className(v) { this.attrs.class = String(v); }
    get className() { return this.attrs.class ?? ''; }

    get textContent() {
        return this.tag === '#text' ? this.data : this.children.map((c) => c.textContent).join('');
    }

    set textContent(v) {
        this.children = [];

        if (String(v) !== '') {
            const text = new FakeNode('#text');
            text.data = String(v);
            this.appendChild(text);
        }
    }

    appendChild(child) { child.parent = this; this.children.push(child); return child; }
    removeChild(child) { this.children = this.children.filter((c) => c !== child); child.parent = null; return child; }
    setAttribute(k, v) { this.attrs[k] = String(v); }
    getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
    removeAttribute(k) { delete this.attrs[k]; }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    focus() { globalThis.__focused = this; }
    getBoundingClientRect() { return this.rect ?? { top: 0, height: 0 }; }
    checkValidity() { return true; }
    reportValidity() { return true; }

    dispatch(type) {
        const event = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        (this.listeners[type] || []).forEach((fn) => fn(event));

        return event;
    }

    all() {
        const out = [];
        const walk = (node) => node.children.forEach((c) => { out.push(c); walk(c); });
        walk(this);

        return out.filter((n) => n.tag !== '#text');
    }

    matches(selector) {
        const m = /^([a-z0-9]+)?(#[\w-]+)?((?:\[[^\]]+\])*)(:checked)?$/i.exec(selector);
        assert.ok(m, `unsupported selector ${selector}`);

        if (m[1] && this.tag !== m[1].toLowerCase()) return false;
        if (m[2] && this.id !== m[2].slice(1)) return false;
        if (m[4] && ! this.checked) return false;

        for (const a of (m[3] || '').matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
            if (! (a[1] in this.attrs)) return false;
            if (a[2] !== undefined && this.attrs[a[1]] !== a[2]) return false;
        }

        return true;
    }

    querySelectorAll(selector) {
        const parts = selector.split(',').map((part) => part.trim());

        return this.all().filter((n) => parts.some((part) => n.matches(part)));
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }

    get elements() {
        const self = this;

        return new Proxy({}, {
            get(_, name) {
                return typeof name === 'string' ? self.all().find((n) => n.name === name) : undefined;
            },
        });
    }
}

function decode(s) {
    return s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

function parse(html) {
    html = html.replace(/<!--[\s\S]*?-->/g, '').replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<style[\s\S]*?<\/style>/gi, '').replace(/<!doctype[^>]*>/i, '');
    const root = new FakeNode('#root');
    let cur = root;
    const token = /<(\/?)([a-zA-Z][\w-]*)((?:\s+[^\s=>/]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*(\/?)>|([^<]+)/g;

    for (const m of html.matchAll(token)) {
        if (m[5] !== undefined) {
            if (m[5].trim() !== '') {
                const text = new FakeNode('#text');
                text.data = decode(m[5]);
                cur.appendChild(text);
            }
            continue;
        }

        const tag = m[2].toLowerCase();

        if (m[1] === '/') {
            let n = cur;
            while (n && n.tag !== tag) n = n.parent;
            if (n && n.parent) cur = n.parent;
            continue;
        }

        const node = new FakeNode(tag);

        for (const a of m[3].matchAll(/([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) {
            node.attrs[a[1]] = decode(a[2] ?? a[3] ?? a[4] ?? '');
        }

        BOOLEAN.forEach((b) => { if (b in node.attrs) node[b] = true; });
        cur.appendChild(node);

        if (! VOID.has(tag) && m[4] !== '/') cur = node;
    }

    return root;
}

// ------------------------------------------------------------------------------------------------ the environment (the account pages)

const [, , htmlPath, scenario] = process.argv;
const dom = parse(readFileSync(htmlPath, 'utf8'));
const calls = [];
const assigned = [];
let reply = () => ({ status: 500, data: null });   // the scripted answer of the API: (method, path, body) => {status, data, headers?}
let delay = 0;

globalThis.document = {
    cookie: 'XSRF-TOKEN=tok',
    getElementById: (id) => dom.querySelector(`#${id}`),
    querySelector: (s) => dom.querySelector(s),
    querySelectorAll: (s) => dom.querySelectorAll(s),
    createElement: (tag) => new FakeNode(tag),
};
globalThis.window = { location: { href: 'http://shop.test/account', origin: 'http://shop.test', search: '', assign: (url) => assigned.push(url) } };

globalThis.fetch = async (path, init = {}) => {
    const method = init.method || 'GET';
    const body = init.body ? JSON.parse(init.body) : undefined;
    calls.push({ method, path, body });

    if (delay > 0) await new Promise((resolve) => setTimeout(resolve, delay));

    const result = reply(method, path, body);

    return { status: result.status, json: async () => result.data, ...(result.headers ? { headers: { get: (name) => result.headers[name] ?? null } } : {}) };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 40));
const q = (s) => dom.querySelector(s);
const status = () => q('[data-sf-account-status]');
// the header's cart badge (storefront-cart.js, on every page that has a cart cookie) reads GET /api/cart by itself: not an account request
const api = () => calls.filter((c) => c.path.startsWith('/api/') && ! (c.method === 'GET' && c.path.split('?')[0] === '/api/cart'));
const errorFor = (scope, name) => scope.querySelector(`[data-sf-error-for="${name}"]`);

async function open() {
    await import(new URL('../../../../resources/js/storefront-account.js', import.meta.url));
    await flush();
}

const card = (n) => dom.querySelectorAll('[data-sf-address]')[n];
const part = (c, name) => c.querySelector(`[${name}]`);
const set = (scope, name, value) => { scope.querySelector(`[name="${name}"]`).value = value; };

function fillAddress(form, over = {}) {
    const values = { recipient_first_name: 'Ivan', recipient_last_name: 'Petrov', phone: '0888123456', city: 'Sofia', postal_code: '1000', address_line_1: 'Vitosha Blvd 1', address_line_2: '', region: '', ...over };
    Object.entries(values).forEach(([name, value]) => set(form, name, value));
}

// ---------------------------------------------------------------------------------------------------- scenarios

const scenarios = {
    // ---- the address book: delete asks ONCE, inline ----
    async delete_first_click_asks_and_sends_nothing() {
        await open();
        const c = card(1);

        part(c, 'data-sf-address-delete').dispatch('click');

        assert.equal(part(c, 'data-sf-address-actions').hidden, true, 'the normal buttons are replaced');
        assert.equal(part(c, 'data-sf-address-confirm').hidden, false, '«Да, изтрий / Откажи» is shown');
        assert.equal(api().length, 0, 'nothing is sent by the first click');
        assert.deepEqual(assigned, []);
        assert.equal(globalThis.__focused, part(c, 'data-sf-address-delete-yes'), 'the focus moves to the question');
    },

    async delete_cancel_restores_the_buttons() {
        await open();
        const c = card(1);

        part(c, 'data-sf-address-delete').dispatch('click');
        part(c, 'data-sf-address-delete-no').dispatch('click');

        assert.equal(part(c, 'data-sf-address-actions').hidden, false);
        assert.equal(part(c, 'data-sf-address-confirm').hidden, true);
        assert.equal(api().length, 0);
        assert.equal(globalThis.__focused, part(c, 'data-sf-address-delete'), 'the focus goes back to «Изтрий»');
    },

    async delete_yes_sends_ONE_delete_and_goes_to_the_notice() {
        const id = card_id_placeholder();
        reply = (method, path) => (method === 'DELETE' && path === `/api/addresses/${id}` ? { status: 204, data: null } : { status: 500, data: null });
        delay = 60;
        await open();
        const c = card(1);

        part(c, 'data-sf-address-delete').dispatch('click');
        part(c, 'data-sf-address-delete-yes').dispatch('click');
        part(c, 'data-sf-address-delete-yes').dispatch('click');   // a second click while it runs
        assert.ok(c.querySelectorAll('button').every((b) => b.disabled === true), 'every button of the card is disabled in flight');
        await new Promise((resolve) => setTimeout(resolve, 200));

        assert.deepEqual(api().map((x) => `${x.method} ${x.path}`), [`DELETE /api/addresses/${id}`], 'ONE request');
        assert.deepEqual(assigned, ['/account/addresses?notice=address-deleted']);
    },

    async make_default_sends_ONE_post_and_goes_to_the_notice() {
        const id = card_id_placeholder();
        reply = (method, path) => (method === 'POST' && path === `/api/addresses/${id}/default` ? { status: 200, data: { id, is_default: true } } : { status: 500, data: null });
        delay = 60;
        await open();
        const c = card(1);

        part(c, 'data-sf-address-default').dispatch('click');
        part(c, 'data-sf-address-default').dispatch('click');
        await new Promise((resolve) => setTimeout(resolve, 200));

        assert.deepEqual(api().map((x) => `${x.method} ${x.path}`), [`POST /api/addresses/${id}/default`]);
        assert.deepEqual(assigned, ['/account/addresses?notice=address-default']);
    },

    async a_429_on_delete_shows_try_again_in_minutes_and_unlocks_the_card() {
        reply = () => ({ status: 429, data: null, headers: { 'Retry-After': '120' } });
        await open();
        const c = card(1);

        part(c, 'data-sf-address-delete').dispatch('click');
        part(c, 'data-sf-address-delete-yes').dispatch('click');
        await flush();

        assert.equal(status().hidden, false);
        assert.match(status().textContent, /2/, 'N min from Retry-After (120 s = 2 min)');
        assert.equal(status().textContent.includes(':minutes'), false, 'the placeholder is filled');
        assert.deepEqual(assigned, []);
        assert.ok(c.querySelectorAll('button').every((b) => b.disabled === false), 'the buttons are enabled again');
    },

    // ---- the address form ----
    async form_422_puts_each_error_under_its_field_and_keeps_the_typed_values() {
        reply = () => ({ status: 422, data: { message: 'x', errors: { phone: ['The phone is invalid.'], city: ['The city is required.'] } } });
        await open();
        const form = q('[data-sf-account-address-form]');

        fillAddress(form, { phone: 'abc', city: '' });
        form.dispatch('submit');
        await flush();

        assert.equal(errorFor(form, 'phone').textContent, 'The phone is invalid.');
        assert.equal(errorFor(form, 'phone').hidden, false);
        assert.equal(errorFor(form, 'city').textContent, 'The city is required.');
        assert.equal(form.querySelector('[name="phone"]').attrs['aria-invalid'], 'true');
        assert.equal(form.querySelector('[name="phone"]').value, 'abc', 'the typed values stay');
        assert.equal(form.querySelector('[name="address_line_1"]').value, 'Vitosha Blvd 1');
        assert.deepEqual(assigned, []);
        assert.equal(form.querySelector('[data-sf-account-submit]').disabled, false, 'enabled again');
    },

    async form_success_goes_to_the_saved_notice_and_never_double_submits() {
        reply = () => ({ status: 201, data: { id: '9' } });
        delay = 60;
        await open();
        const form = q('[data-sf-account-address-form]');

        fillAddress(form, { address_line_2: '' });
        form.dispatch('submit');
        assert.equal(form.querySelector('[data-sf-account-submit]').disabled, true, 'disabled in flight');
        form.dispatch('submit');                                    // a second submit while it runs
        await new Promise((resolve) => setTimeout(resolve, 200));

        const sent = api();

        assert.equal(sent.length, 1, 'ONE request');
        assert.equal(`${sent[0].method} ${sent[0].path}`, 'POST /api/addresses');
        assert.deepEqual(sent[0].body, { delivery_type: 'street_address', recipient_first_name: 'Ivan', recipient_last_name: 'Petrov', phone: '0888123456', country: 'BG', city: 'Sofia', postal_code: '1000', address_line_1: 'Vitosha Blvd 1', address_line_2: null, region: null });
        assert.deepEqual(assigned, ['/account/addresses?notice=address-saved']);
        assert.equal(form.querySelector('[data-sf-account-submit]').disabled, true, 'it stays disabled while the browser navigates');
    },

    async edit_form_puts_to_the_address() {
        reply = () => ({ status: 200, data: { id: '5' } });
        await open();
        const form = q('[data-sf-account-address-form]');
        const id = form.getAttribute('data-address-id');

        assert.notEqual(id, '');
        set(form, 'city', 'Plovdiv');
        form.dispatch('submit');
        await flush();

        assert.deepEqual(api().map((x) => `${x.method} ${x.path}`), [`PUT /api/addresses/${id}`]);
        assert.equal(api()[0].body.city, 'Plovdiv');
        assert.deepEqual(assigned, ['/account/addresses?notice=address-saved']);
    },

    async form_follows_the_region_rule_of_the_country() {
        const form = q('[data-sf-account-address-form]');

        form.attrs['data-region-policy'] = JSON.stringify({ default: 'optional', countries: { DE: 'hidden', FR: 'required' } });
        await open();
        const box = q('[data-sf-region-field]');
        const country = form.querySelector('[name="country"]');
        const region = form.querySelector('[name="region"]');

        assert.equal(box.hidden, false, 'optional by default');
        region.value = 'Pernik';

        country.value = 'DE';                                       // the policy set above: DE hidden, FR required
        country.dispatch('change');
        assert.equal(box.hidden, true);
        assert.equal(region.value, '', 'a hidden region is cleared and not sent');

        country.value = 'FR';
        country.dispatch('change');
        assert.equal(box.hidden, false);
        assert.equal(region.required, true);
        assert.equal(region.attrs['aria-required'], 'true');
    },

    async form_429_shows_try_again_in_minutes() {
        reply = () => ({ status: 429, data: null, headers: { 'Retry-After': '300' } });
        await open();
        const form = q('[data-sf-account-address-form]');

        fillAddress(form);
        form.dispatch('submit');
        await flush();

        assert.match(status().textContent, /5/);
        assert.deepEqual(assigned, []);
        assert.equal(form.querySelector('[data-sf-account-submit]').disabled, false);
    },

    // ---- details: the password ----
    async password_mismatch_is_caught_before_sending() {
        await open();
        const form = q('[data-sf-account-password-form]');

        set(form, 'current_password', 'old-password-1');
        set(form, 'password', 'a-brand-new-pass-77');
        set(form, 'password_confirmation', 'a-brand-new-pass-78');
        form.dispatch('submit');
        await flush();

        assert.equal(api().length, 0, 'nothing is sent');
        assert.equal(errorFor(form, 'password_confirmation').hidden, false);
        assert.ok(errorFor(form, 'password_confirmation').textContent.length > 0);
        assert.equal(form.querySelector('[data-sf-account-submit]').disabled, false);
    },

    async password_422_shows_the_error_under_the_field() {
        reply = () => ({ status: 422, data: { message: 'x', errors: { current_password: ['The current password is wrong.'] } } });
        await open();
        const form = q('[data-sf-account-password-form]');

        set(form, 'current_password', 'wrong');
        set(form, 'password', 'a-brand-new-pass-77');
        set(form, 'password_confirmation', 'a-brand-new-pass-77');
        form.dispatch('submit');
        await flush();

        assert.equal(errorFor(form, 'current_password').textContent, 'The current password is wrong.');
        assert.deepEqual(assigned, []);
        assert.equal(form.querySelector('[name="password"]').value, 'a-brand-new-pass-77', 'the typed values stay');
    },

    // The API keeps THIS session signed in (it adopts the new session version) and ends the others: success = the details page with the notice.
    async password_success_stays_signed_in_and_goes_to_the_details_notice() {
        reply = () => ({ status: 200, data: { message: 'ok' } });
        await open();
        const form = q('[data-sf-account-password-form]');

        set(form, 'current_password', 'old-password-1');
        set(form, 'password', 'a-brand-new-pass-77');
        set(form, 'password_confirmation', 'a-brand-new-pass-77');
        form.dispatch('submit');
        await flush();

        assert.deepEqual(api().map((x) => `${x.method} ${x.path}`), ['PUT /api/account/password']);
        assert.deepEqual(api()[0].body, { current_password: 'old-password-1', password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77' });
        assert.deepEqual(assigned, ['/account/details?notice=password-changed']);
    },

    async password_401_means_the_session_is_gone_and_goes_to_the_login() {
        reply = () => ({ status: 401, data: { message: 'Unauthenticated.' } });
        await open();
        const form = q('[data-sf-account-password-form]');

        set(form, 'current_password', 'old-password-1');
        set(form, 'password', 'a-brand-new-pass-77');
        set(form, 'password_confirmation', 'a-brand-new-pass-77');
        form.dispatch('submit');
        await flush();

        assert.deepEqual(assigned, ['/account/login']);
    },

    // ---- details: the email ----
    async email_success_goes_to_the_email_sent_notice() {
        reply = () => ({ status: 202, data: { message: 'accepted' } });
        await open();
        const form = q('[data-sf-account-email-form]');

        set(form, 'email', ' new@example.com ');
        set(form, 'current_password', 'old-password-1');
        form.dispatch('submit');
        await flush();

        assert.deepEqual(api().map((x) => `${x.method} ${x.path}`), ['POST /api/account/email']);
        assert.deepEqual(api()[0].body, { email: 'new@example.com', current_password: 'old-password-1' });
        assert.deepEqual(assigned, ['/account/details?notice=email-sent']);
    },

    async email_422_wrong_current_password_shows_under_the_field() {
        reply = () => ({ status: 422, data: { message: 'x', errors: { current_password: ['The current password is wrong.'] } } });
        await open();
        const form = q('[data-sf-account-email-form]');

        set(form, 'email', 'new@example.com');
        set(form, 'current_password', 'wrong');
        form.dispatch('submit');
        await flush();

        assert.equal(errorFor(form, 'current_password').textContent, 'The current password is wrong.');
        assert.deepEqual(assigned, []);
    },
};

// the id of the second card, read from the page (the PHP runner renders two addresses, the first is the default)
function card_id_placeholder() {
    return dom.querySelectorAll('[data-sf-address]')[1].getAttribute('data-address-id');
}

assert.ok(scenarios[scenario], `unknown scenario ${scenario}`);
await scenarios[scenario]();
console.log(`OK ${scenario}`);
