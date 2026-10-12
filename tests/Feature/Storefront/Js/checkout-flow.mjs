// Behavioural check of the storefront checkout script (resources/js/storefront-checkout.js) without a browser and without a
// new dependency: the REAL rendered /checkout page is parsed into a tiny fake DOM, the REAL script (and the cart script it
// imports) runs against it with a scripted fetch, and the courier-then-type flow is driven the way a customer would.
//
//   node checkout-flow.mjs <rendered-checkout.html> <scenario>
//
// Exit code 0 and the line "OK <scenario>" when every assertion holds; the stack of the first failed assertion otherwise.
// Run by tests/Feature/Storefront/StorefrontCheckoutFlowTest.php (skipped when node is not installed).
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
    }

    get id() { return this.attrs.id ?? ''; }
    get name() { return this.attrs.name ?? ''; }
    get firstChild() { return this.children[0] ?? null; }
    get value() { return this._value ?? this.attrs.value ?? ''; }
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

    querySelectorAll(selector) { return this.all().filter((n) => n.matches(selector)); }
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

// ------------------------------------------------------------------------------------------------ the environment

const [, , htmlPath, scenario] = process.argv;
const dom = parse(readFileSync(htmlPath, 'utf8'));
const calls = [];
const replaced = [];
let generation = 1;
let dropped = [];
let checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };

globalThis.document = {
    cookie: 'easyco_has_cart=1; XSRF-TOKEN=tok',
    getElementById: (id) => dom.querySelector(`#${id}`),
    querySelector: (s) => dom.querySelector(s),
    querySelectorAll: (s) => dom.querySelectorAll(s),
    createElement: (tag) => new FakeNode(tag),
};
globalThis.window = {
    location: { href: 'http://shop.test/checkout', origin: 'http://shop.test', replace: (u) => replaced.push(u) },
    setTimeout: (fn) => setTimeout(fn, 0),
    clearTimeout,
};

const money = (minor) => ({ minor, currency: 'EUR' });
const fmt = (minor) => `${(minor / 100).toFixed(2)} €`;
const cart = {
    cart_id: 11,
    lines: [{ sku: 'S1', product_name: 'Dress', quantity: 2, attributes: [{ value: 'Red' }, { value: 'M' }], line_total: money(2000), image: null }],
    subtotal: money(2000), promotion: null, total: money(2000),
};

const method = (id, name, courier, type, pickup, minor) => ({
    id, name, courier, delivery_type: type, requires_pickup_point: pickup, available: true, price: money(minor), handle: `h${id}-${generation}`,
});

function quoteFor(kind) {
    const all = kind === 'street_address'
        ? [method(1, 'Econt address', 'Еконт', 'address', false, 500), method(2, 'Local courier', null, null, false, 700)]
        : [method(4, 'Econt office', 'Еконт', 'office', true, 300), method(5, 'Econt locker', 'Еконт', 'locker', true, 0), method(6, 'Speedy office', 'Спиди', 'office', true, 350)];
    const groups = kind === 'street_address'
        ? [{ courier: 'Еконт', methods: [1] }, { courier: null, methods: [2] }]
        : [{ courier: 'Еконт', methods: [4, 5] }, { courier: 'Спиди', methods: [6] }];
    const methods = all.filter((m) => ! dropped.includes(m.id));

    return {
        cart_id: 11, currency: 'EUR', goods_after_discount: money(2000), methods,
        groups: groups.map((g) => ({ ...g, methods: g.methods.filter((id) => ! dropped.includes(id)) })).filter((g) => g.methods.length > 0),
        free_shipping_hint: { text: 'Add 5 € more for free delivery' },
    };
}

globalThis.fetch = async (path, init = {}) => {
    const method_ = init.method || 'GET';
    const body = init.body ? JSON.parse(init.body) : undefined;
    calls.push({ method: method_, path, body });
    let result = { status: 404, data: null };

    if (method_ === 'GET' && path === '/api/cart') result = { status: 200, data: cart };
    if (method_ === 'POST' && path === '/api/shipping/quote') result = { status: 200, data: quoteFor(body.delivery_type) };
    if (method_ === 'POST' && path === '/api/checkout') result = checkoutResponse;

    return { status: result.status, json: async () => result.data };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 40));
const form = dom.querySelector('[data-sf-checkout-form]');
const i18n = dom.querySelector('#sf-i18n');
const T = (key) => i18n.getAttribute(`data-m-${key.replace(/_/g, '-')}`);
const q = (s) => dom.querySelector(s);
const placeOrder = q('[data-sf-place-order]');
const rowsOf = (list) => list.querySelectorAll('label').map((label) => ({ text: label.textContent.trim().replace(/\s+/g, ' '), radio: label.children[0] }));
const courierRows = () => rowsOf(q('[data-sf-couriers]'));
const typeRows = () => rowsOf(q('[data-sf-types]'));
const quotePosts = () => calls.filter((c) => c.method === 'POST' && c.path === '/api/shipping/quote');

function choose(radio) {
    form.all().filter((n) => n.tag === 'input' && n.attrs.type === 'radio' && n.name === radio.name).forEach((n) => { n.checked = false; });
    radio.checked = true;
    radio.dispatch('change');
}

async function typeInto(name, value) {
    form.elements[name].value = value;
    form.elements[name].dispatch('input');
    await flush();
}

function fillContact() {
    form.elements.email.value = 'a@example.com';
    form.elements.recipient_name.value = 'Ana Petrova';
    form.elements.phone.value = '0888123456';
}

async function submit() {
    const event = form.dispatch('submit');
    await flush();

    return event;
}

const lastCheckout = () => calls.filter((c) => c.path === '/api/checkout').at(-1);

await import(new URL('../../../../resources/js/storefront-checkout.js', import.meta.url));
await flush();

// ---------------------------------------------------------------------------------------------------- scenarios

const scenarios = {
    // The form asks for nothing it cannot use yet; the typed courier / settlement / kind fields are gone.
    async structure() {
        assert.equal(form.elements.carrier_code, undefined, 'no typed courier field');
        assert.equal(form.elements.settlement, undefined, 'no separate settlement field');
        assert.equal(form.elements.destination_kind, undefined, 'no address/office/locker radios before a courier is chosen');
        assert.equal(q('[data-sf-checkout-loading]').hidden, true);
        assert.equal(q('[data-sf-quote-status]').textContent, T('ck_enter_city'), 'no quote before the place is known');
        assert.equal(quotePosts().length, 0);
        assert.equal(q('[data-sf-courier-box]').hidden, true);
        assert.equal(placeOrder.disabled, true);
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2000));
    },

    // Courier first, then the delivery type; a courier with ONE type skips the second choice.
    async courier_then_type() {
        await typeInto('city', 'Sofia');

        const posts = quotePosts();
        assert.deepEqual(posts.map((p) => p.body.delivery_type), ['street_address', 'pickup_point']);
        assert.ok(posts.every((p) => p.body.settlement === 'Sofia' && p.body.country === 'BG'));

        // Couriers in the order of the groups, the courier-less group last as "Other"; the single-type courier shows its type and price.
        assert.deepEqual(courierRows().map((r) => r.text), [
            'Еконт',
            `Спиди — ${T('ck_kind_office')} — ${fmt(350)}`,
            `${T('ck_courier_other')} — ${T('ck_kind_address')} — ${fmt(700)}`,
        ]);
        assert.equal(q('[data-sf-courier-box]').hidden, false);
        assert.equal(q('[data-sf-type-box]').hidden, true, 'no second choice before a courier is chosen');
        assert.equal(q('[data-sf-shipping-hint]').textContent, 'Add 5 € more for free delivery', 'the API sentence, shown once');
        assert.equal(placeOrder.disabled, true);

        choose(courierRows()[0].radio);
        assert.equal(q('[data-sf-type-box]').hidden, false);
        assert.deepEqual(typeRows().map((r) => r.text), [
            `${T('ck_kind_address')} — ${fmt(500)}`,
            `${T('ck_kind_office')} — ${fmt(300)}`,
            `${T('ck_kind_locker')} — ${T('ck_free')}`,
        ]);
        assert.equal(placeOrder.disabled, true, 'the type is still to choose');
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);

        // An office: the pick-up fields appear, the address ones do not; the bar total follows the choice.
        choose(typeRows()[1].radio);
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        assert.equal(placeOrder.disabled, false);
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2300));

        // An address instead: the other way round.
        choose(typeRows()[0].radio);
        assert.equal(q('[data-sf-address-fields]').hidden, false);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2500));

        // The pick-up order: carrier_code is the chosen courier, the settlement is the one place field.
        choose(typeRows()[1].radio);
        fillContact();
        form.elements.pickup_point_reference.value = 'R-12';
        form.elements.pickup_point_name.value = 'Office Center';
        form.elements.pickup_point_address.value = '1 Main St';
        await submit();

        const body = lastCheckout().body;
        assert.deepEqual(body, {
            cart_id: 11, email: 'a@example.com', recipient_name: 'Ana Petrova', phone: '0888123456', payment_method: body.payment_method,
            delivery_type: 'pickup_point', country: 'BG', shipping_method_id: '4', quote_handle: 'h4-1', expected_shipping_minor: 300,
            settlement: 'Sofia', carrier_code: 'Еконт', pickup_point_reference: 'R-12', pickup_point_name: 'Office Center', pickup_point_address: '1 Main St',
        });
        assert.deepEqual(replaced, ['http://shop.test/order/received/5?expires=1&signature=abc']);
    },

    async single_type_courier() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[1].radio);

        assert.equal(q('[data-sf-type-box]').hidden, true, 'one delivery type: no second choice');
        assert.equal(placeOrder.disabled, false);
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);

        fillContact();
        form.elements.pickup_point_reference.value = 'S-1';
        form.elements.pickup_point_name.value = 'Speedy Office';
        form.elements.pickup_point_address.value = '2 Side St';
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.carrier_code, 'Спиди');
        assert.equal(body.shipping_method_id, '6');
        assert.equal(body.expected_shipping_minor, 350);
    },

    // A method without a courier is the group "Other"; it is an address method here and sends no carrier_code.
    async courierless_method() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);

        assert.equal(q('[data-sf-type-box]').hidden, true);
        assert.equal(q('[data-sf-address-fields]').hidden, false);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);

        fillContact();
        form.elements.address_line_1.value = '5 Long St';
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.delivery_type, 'street_address');
        assert.equal(body.city, 'Sofia');
        assert.equal(body.address_line_1, '5 Long St');
        assert.equal(body.shipping_method_id, '2');
        assert.equal('carrier_code' in body, false);
        assert.equal('settlement' in body, false);
    },

    // The destination changes: the choice stays while the new quote still offers it (new handle), the button waits for the answer.
    async requote_keeps_choice() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        choose(typeRows()[1].radio);
        assert.equal(placeOrder.disabled, false);

        generation = 2;
        form.elements.city.value = 'Plovdiv';
        form.elements.city.dispatch('input');
        assert.equal(placeOrder.disabled, true, 'locked while the new quote is pending');
        await flush();

        assert.equal(placeOrder.disabled, false);
        assert.equal(courierRows()[0].radio.checked, true);
        assert.equal(typeRows()[1].radio.checked, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);

        fillContact();
        form.elements.pickup_point_reference.value = 'R-12';
        form.elements.pickup_point_name.value = 'Office Center';
        form.elements.pickup_point_address.value = '1 Main St';
        await submit();

        assert.equal(lastCheckout().body.quote_handle, 'h4-2', 'the handle of the NEW quote');
        assert.equal(lastCheckout().body.settlement, 'Plovdiv');
    },

    // A choice that disappears from the new quote is cleared with a short message.
    async requote_clears_vanished_choice() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        choose(typeRows()[1].radio);

        dropped = [4];
        await typeInto('city', 'Varna');

        assert.equal(q('[data-sf-quote-status]').textContent, T('ck_choice_gone'));
        assert.equal(placeOrder.disabled, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(courierRows()[0].radio.checked, true, 'the courier is still offered, so it stays');
        assert.deepEqual(typeRows().map((r) => r.radio.checked), [false, false]);

        // The whole courier gone: cleared as well.
        dropped = [1, 4, 5];
        choose(typeRows()[0].radio);
        assert.equal(placeOrder.disabled, false);
        await typeInto('city', 'Ruse');
        assert.equal(q('[data-sf-quote-status]').textContent, T('ck_choice_gone'));
        assert.deepEqual(courierRows().map((r) => r.radio.checked), [false, false]);
        assert.equal(q('[data-sf-type-box]').hidden, true);
    },

    // The mobile bar: a real button that opens the same summary in place.
    async summary_toggle() {
        const toggle = q('[data-sf-summary-toggle]');
        const panel = q('#sf-summary-panel');

        assert.equal(toggle.tag, 'button');
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(toggle.getAttribute('aria-controls'), 'sf-summary-panel');
        assert.equal(panel.getAttribute('data-open'), 'false');
        const closed = q('[data-sf-summary-toggle-label]').textContent;

        toggle.dispatch('click');
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');
        assert.equal(panel.getAttribute('data-open'), 'true');
        assert.equal(q('[data-sf-summary-toggle-label]').textContent, T('ck_summary_hide'));
        assert.equal(closed, T('ck_summary_show'));

        toggle.dispatch('click');
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(q('[data-sf-summary-toggle-label]').textContent, T('ck_summary_show'));

        // The summary lines: name with the quantity, the variation label, the line total.
        assert.match(panel.textContent, /2 × Dress/);
        assert.match(panel.textContent, /Red \/ M/);
        assert.ok(panel.textContent.includes(fmt(2000)));
    },
};

assert.ok(scenarios[scenario], `unknown scenario ${scenario}`);
await scenarios[scenario]();
console.log(`OK ${scenario}`);
