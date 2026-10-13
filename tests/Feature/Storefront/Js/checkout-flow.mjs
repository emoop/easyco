// Behavioural check of the storefront checkout script (resources/js/storefront-checkout.js) without a browser and without a
// new dependency: the REAL rendered /checkout page is parsed into a tiny fake DOM, the REAL script (and the cart script it
// imports) runs against it with a scripted fetch, and the all-kinds delivery flow is driven the way a customer would.
//
//   node checkout-flow.mjs <rendered-checkout.html> <scenario> [<json file for region_agreement>]
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

const [, , htmlPath, scenario, extraPath] = process.argv;
const dom = parse(readFileSync(htmlPath, 'utf8'));
const calls = [];
const replaced = [];
let generation = 1;
let dropped = [];
let unavailable = [];
let priceOf = {};
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

// The scripted `all` answer (shipping-domain-design.md 9.3.4). Methods: 1 Econt address 5.00, 4 Econt office 3.00, 5 Econt locker FREE,
// 6 Speedy office 3.50 (courier Спиди, one kind), 2 a courier-less address method 7.00. A request that is not an `all` request is a
// test failure: the page must send ONE `all` request and never a pick-up kind with it.
function dest(preview, id, kind, minor) {
    const off = unavailable.includes(`${id}:${kind}`);

    return {
        available: ! off, price: off ? null : money(priceOf[`${id}:${kind}`] ?? minor), handle: preview || off ? null : `h${id}-${kind}-${generation}`,
        free_applies: minor === 0, free_above_minor: null, remaining_to_free_minor: null, unavailable_reason: off ? 'no_quote' : null,
    };
}

function quoteAll(body) {
    assert.equal(body.delivery_type, 'all', 'the checkout asks the all-kinds quote');
    assert.equal('pickup_kind' in body, false, 'the API prohibits pickup_kind with all');
    assert.equal('cart_id' in body, false, 'the cart is the session cart');

    const preview = ! ('settlement' in body);
    const all = [
        { id: 1, name: 'Econt address', courier: 'Еконт', delivery_type: 'address', destinations: { address: dest(preview, 1, 'address', 500) } },
        { id: 4, name: 'Econt office', courier: 'Еконт', delivery_type: 'office', destinations: { office: dest(preview, 4, 'office', 300) } },
        { id: 5, name: 'Econt locker', courier: 'Еконт', delivery_type: 'locker', destinations: { locker: dest(preview, 5, 'locker', 0) } },
        { id: 6, name: 'Speedy office', courier: 'Спиди', delivery_type: 'office', destinations: { office: dest(preview, 6, 'office', 350) } },
        { id: 2, name: 'Local courier', courier: null, delivery_type: null, destinations: { address: dest(preview, 2, 'address', 700) } },
    ].filter((m) => ! dropped.includes(m.id));
    const groups = [
        { courier: 'Еконт', ids: [1, 4, 5], from: 0, destinations: ['address', 'office', 'locker'] },
        { courier: 'Спиди', ids: [6], from: 350, destinations: ['office'] },
        { courier: null, ids: [2], from: 700, destinations: ['address'] },
    ].map((g) => ({ courier: g.courier, methods: g.ids.filter((id) => ! dropped.includes(id)).map(String), from_minor: g.from, currency: 'EUR', destinations: g.destinations }))
        .filter((g) => g.methods.length > 0);

    return {
        cart_id: 11, currency: 'EUR', goods_after_discount: money(2000), mode: 'all', preview, methods: all.map((m) => ({ ...m, id: String(m.id) })), groups,
        free_shipping_hint: { text: 'Add 5 € more for free delivery', destinations: ['office', 'locker'] },
    };
}

globalThis.fetch = async (path, init = {}) => {
    const method_ = init.method || 'GET';
    const body = init.body ? JSON.parse(init.body) : undefined;
    calls.push({ method: method_, path, body });
    let result = { status: 404, data: null };

    if (method_ === 'GET' && path === '/api/cart') result = { status: 200, data: cart };
    if (method_ === 'POST' && path === '/api/shipping/quote') result = { status: 200, data: quoteAll(body) };
    if (method_ === 'POST' && path === '/api/checkout') result = checkoutResponse;

    return { status: result.status, json: async () => result.data };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 40));
const form = dom.querySelector('[data-sf-checkout-form]');
const i18n = dom.querySelector('#sf-i18n');
const T = (key) => i18n.getAttribute(`data-m-${key.replace(/_/g, '-')}`);
const q = (s) => dom.querySelector(s);
const placeOrder = q('[data-sf-place-order]');
const kindSelect = q('[data-sf-kind-select]');
const rowsOf = (list) => list.querySelectorAll('label').map((label) => ({ text: label.textContent.trim().replace(/\s+/g, ' '), radio: label.children[0] }));
const courierRows = () => rowsOf(q('[data-sf-couriers]'));
const kindOptions = () => kindSelect.children.map((o) => ({ value: o.attrs.value, text: o.textContent }));
const quotePosts = () => calls.filter((c) => c.method === 'POST' && c.path === '/api/shipping/quote');
const checkoutPosts = () => calls.filter((c) => c.path === '/api/checkout');
const lastCheckout = () => checkoutPosts().at(-1);
const from = (minor) => T('ck_from').replace(':price', minor === 0 ? T('ck_free') : fmt(minor));
const status = () => q('[data-sf-quote-status]').textContent;

function choose(radio) {
    form.all().filter((n) => n.tag === 'input' && n.attrs.type === 'radio' && n.name === radio.name).forEach((n) => { n.checked = false; });
    radio.checked = true;
    radio.dispatch('change');
}

function chooseKind(value) {
    kindSelect.value = value;
    kindSelect.dispatch('change');
}

async function typeInto(name, value) {
    form.elements[name].value = value;
    form.elements[name].dispatch('input');
    await flush();
}

function set(name, value) {
    form.elements[name].value = value;
}

function fillContact() {
    set('email', 'a@example.com');
    set('recipient_name', 'Ana Petrova');
    set('phone', '0888123456');
}

function fillPoint(code) {
    set('pickup_point_reference', code);
    set('pickup_point_name', `Point ${code}`);
    set('pickup_point_address', `${code} Main St`);
    PICKUP.forEach((n) => form.elements[n].dispatch('input'));
}

const PICKUP = ['pickup_point_reference', 'pickup_point_name', 'pickup_point_address'];

async function submit() {
    const event = form.dispatch('submit');
    await flush();

    return event;
}

const checkoutModule = await import(new URL('../../../../resources/js/storefront-checkout.js', import.meta.url));
await flush();

// ---------------------------------------------------------------------------------------------------- scenarios

const scenarios = {
    // The markup: no typed courier / settlement field, the region and the note are there, and the page already asked for a PREVIEW.
    async structure() {
        assert.equal(form.elements.carrier_code, undefined, 'no typed courier field');
        assert.equal(form.elements.settlement, undefined, 'no separate settlement field');
        assert.equal(form.elements.destination_kind, undefined);
        assert.equal(q('[data-sf-checkout-loading]').hidden, true);
        assert.equal(placeOrder.disabled, true);
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(q('[data-sf-type-box]').hidden, true, 'no kind choice before a courier');
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2000));

        const region = form.elements.region;
        assert.equal(region.attrs.maxlength, '100');
        assert.equal(region.attrs.autocomplete, 'address-level1');
        assert.ok(q('[data-sf-region-field]').querySelector('[data-sf-error-for="region"]'), 'sf-err-region holder');
        assert.ok(q('[data-sf-address-fields]').all().includes(region), 'the region lives in the street-address block only');

        const note = form.elements.customer_note;
        assert.equal(note.tag, 'textarea');
        assert.equal(note.attrs.maxlength, '1000');
        assert.equal(note.attrs.rows, '3');
        assert.ok(q('[data-sf-error-for="customer_note"]'), 'sf-err-customer_note holder');
        assert.equal(q('[data-sf-note-counter]').textContent, '0 / 1000');
    },

    // Country only: ONE `all` request WITHOUT a settlement; couriers at once with the indicative "from" price; nothing can be placed.
    async preview_before_place() {
        assert.equal(quotePosts().length, 1, 'ONE request before any place is typed');
        assert.deepEqual(quotePosts()[0].body, { delivery_type: 'all', country: 'BG' });

        assert.deepEqual(courierRows().map((r) => r.text), [
            `Еконт — ${from(0)}`,
            `Спиди — ${from(350)}`,
            `${T('ck_courier_other')} — ${from(700)}`,
        ]);
        assert.equal(q('[data-sf-courier-box]').hidden, false);
        assert.equal(status(), T('ck_preview_note'));
        assert.equal(placeOrder.disabled, true);

        // Even a complete-looking choice is not final: no handle exists, the total does not take the price, nothing is posted.
        choose(courierRows()[1].radio);
        assert.equal(q('[data-sf-type-box]').hidden, false);
        assert.equal(placeOrder.disabled, true, 'a preview price is never final');
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2000), 'the indicative price is not summed into the total');
        fillContact();
        fillPoint('S-1');
        assert.equal(placeOrder.disabled, true);
        await submit();
        assert.equal(checkoutPosts().length, 0, 'a preview selection is never submitted');
    },

    // Place typed: exactly ONE request (not two), `all`, with the settlement, and never a pick-up kind.
    async one_request_after_place() {
        const before = quotePosts().length;
        await typeInto('city', 'Sofia');

        assert.equal(quotePosts().length - before, 1, 'one request for the place, not one per kind');
        const body = quotePosts().at(-1).body;
        assert.deepEqual(body, { delivery_type: 'all', country: 'BG', settlement: 'Sofia' });
        assert.equal('pickup_kind' in body, false);

        // The postal code (street kind only) joins the same single request after the debounce.
        await typeInto('postal_code', '1000');
        assert.deepEqual(quotePosts().at(-1).body, { delivery_type: 'all', country: 'BG', settlement: 'Sofia', postal_code: '1000' });
        assert.equal(quotePosts().length - before, 2);
        assert.equal(status(), T('ck_choose_courier'));
        assert.equal(q('[data-sf-shipping-hint]').textContent, 'Add 5 € more for free delivery', 'the API sentence, as the cart shows it');
    },

    // A courier with several kinds asks (native <select>, "Kind — price"); a courier with ONE kind picks it and shows text.
    async courier_then_kind_dropdown() {
        await typeInto('city', 'Sofia');

        assert.deepEqual(courierRows().map((r) => r.text), [
            `Еконт — ${from(0)}`,
            `Спиди — ${T('ck_kind_office')} — ${fmt(350)}`,
            `${T('ck_courier_other')} — ${T('ck_kind_address')} — ${fmt(700)}`,
        ]);
        assert.equal(q('[data-sf-type-box]').hidden, true, 'no kind choice before a courier is chosen');

        choose(courierRows()[0].radio);
        assert.equal(q('[data-sf-type-box]').hidden, false);
        assert.equal(kindSelect.tag, 'select', 'a native select');
        assert.equal(kindSelect.hidden, false);
        assert.deepEqual(kindOptions(), [
            { value: '', text: T('ck_choose_type') },
            { value: '1:address', text: `${T('ck_kind_address')} — ${fmt(500)}` },
            { value: '4:office', text: `${T('ck_kind_office')} — ${fmt(300)}` },
            { value: '5:locker', text: `${T('ck_kind_locker')} — ${T('ck_free')}` },
        ]);
        assert.equal(kindSelect.value, '');
        assert.equal(placeOrder.disabled, true, 'the kind is still to choose');
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(status(), T('ck_choose_type'));

        chooseKind('4:office');
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2300));
        chooseKind('1:address');
        assert.equal(q('[data-sf-address-fields]').hidden, false);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2500));

        // ONE kind: no select, the kind is chosen for the customer and shown as text.
        choose(courierRows()[1].radio);
        assert.equal(kindSelect.hidden, true, 'one kind: no dropdown');
        assert.equal(q('[data-sf-kind-text]').hidden, false);
        assert.equal(q('[data-sf-kind-text]').textContent, `${T('ck_kind_office')} — ${fmt(350)}`);
        assert.equal(q('[data-sf-pickup-fields]').hidden, false, 'selected automatically');
        assert.equal(placeOrder.disabled, true, 'the point fields are still empty');
        fillContact();
        fillPoint('S-1');
        assert.equal(placeOrder.disabled, false);
        await submit();

        assert.deepEqual(lastCheckout().body, {
            cart_id: 11, email: 'a@example.com', recipient_name: 'Ana Petrova', phone: '0888123456', payment_method: lastCheckout().body.payment_method,
            delivery_type: 'pickup_point', country: 'BG', shipping_method_id: '6', quote_handle: 'h6-office-1', expected_shipping_minor: 350,
            settlement: 'Sofia', carrier_code: 'Спиди', pickup_point_kind: 'office',
            pickup_point_reference: 'S-1', pickup_point_name: 'Point S-1', pickup_point_address: 'S-1 Main St',
        });
        assert.deepEqual(replaced, ['http://shop.test/order/received/5?expires=1&signature=abc']);
    },

    // The owner's scenario: courier A to an address, then courier B to an office, then another point: every switch is free of a request,
    // the typed pick-up point is dropped each time, everything else the customer typed stays.
    async switch_courier_and_kind_keeps_data() {
        await typeInto('city', 'Sofia');
        const requests = quotePosts().length;

        fillContact();
        set('customer_note', 'Please call 😀');
        form.elements.customer_note.dispatch('input');

        choose(courierRows()[0].radio);
        chooseKind('1:address');
        set('postal_code', '1000');
        set('address_line_1', '5 Long St');
        set('address_line_2', 'Floor 2');
        set('region', 'Sofia-city');

        // Courier B serves no address: the kind cannot be kept, its one kind is chosen for the customer; the pick-up fields are asked.
        choose(courierRows()[1].radio);
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);
        assert.equal(q('[data-sf-address-fields]').hidden, true);
        fillPoint('S-1');

        // Back to courier A: its office is kept (the kind the customer had), but the point belonged to the other courier: dropped.
        choose(courierRows()[0].radio);
        assert.equal(kindSelect.value, '4:office', 'the kind is kept when the new courier serves it');
        assert.deepEqual(PICKUP.map((n) => form.elements[n].value), ['', '', ''], 'the point belonged to the other courier');
        fillPoint('E-9');

        // Another kind of the same courier: the point belonged to the office: dropped again.
        chooseKind('5:locker');
        assert.deepEqual(PICKUP.map((n) => form.elements[n].value), ['', '', '']);
        assert.equal(placeOrder.disabled, true);
        fillPoint('L-3');
        assert.equal(placeOrder.disabled, false);

        // Everything else is untouched, the street fields included (an address would come back to them).
        assert.equal(form.elements.email.value, 'a@example.com');
        assert.equal(form.elements.recipient_name.value, 'Ana Petrova');
        assert.equal(form.elements.phone.value, '0888123456');
        assert.equal(form.elements.region.value, 'Sofia-city');
        assert.equal(form.elements.address_line_1.value, '5 Long St');
        assert.equal(form.elements.postal_code.value, '1000');
        assert.equal(form.elements.customer_note.value, 'Please call 😀');
        assert.equal(form.querySelector('input[name="payment_method"]:checked').checked, true);
        assert.equal(quotePosts().length, requests, 'switching among the quoted options sends no request');

        await submit();

        const body = lastCheckout().body;
        assert.deepEqual(body, {
            cart_id: 11, email: 'a@example.com', recipient_name: 'Ana Petrova', phone: '0888123456', payment_method: body.payment_method,
            delivery_type: 'pickup_point', country: 'BG', shipping_method_id: '5', quote_handle: 'h5-locker-1', expected_shipping_minor: 0,
            settlement: 'Sofia', carrier_code: 'Еконт', pickup_point_kind: 'locker',
            pickup_point_reference: 'L-3', pickup_point_name: 'Point L-3', pickup_point_address: 'L-3 Main St', customer_note: 'Please call 😀',
        });
        assert.equal('region' in body, false, 'the region belongs to a street address only');
        assert.equal(quotePosts().length, requests);
    },

    // Rule (A): a changed price is shown and CONFIRMED by the customer; it is never resubmitted by itself. The old handle is bound to the
    // old amount, so ONE fresh quote captures the new handle for the same (courier, method, kind) and the second press carries both.
    async rule_a_price_changed() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[1].radio);
        fillContact();
        fillPoint('S-1');
        const quotes = quotePosts().length;

        priceOf = { '6:office': 420 };
        generation = 2;
        checkoutResponse = { status: 409, data: { message: 'The shipping price changed.', reason: 'shipping_price_changed', price: money(420) } };
        form.dispatch('submit');
        await new Promise((resolve) => setTimeout(resolve, 0));

        // The refusal is handled and the fresh quote is scheduled, not yet answered: the figure is on screen, the button is locked.
        assert.equal(checkoutPosts().length, 1);
        assert.equal(placeOrder.disabled, true, 'locked while the fresh quote runs');
        assert.equal(q('[data-sf-checkout-status]').textContent, T('ck_price_changed').replace(':price', fmt(420)));
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2420), 'the fresh figure is the one in the total');

        await flush();
        assert.equal(quotePosts().length - quotes, 1, 'exactly ONE fresh quote');
        assert.equal(placeOrder.disabled, false, 'free again when the quote ends');
        assert.equal(q('[data-sf-checkout-status]').textContent, T('ck_price_changed').replace(':price', fmt(420)), 'the message survives the re-quote');
        assert.equal(q('[data-sf-checkout-status]').getAttribute('data-state'), 'price-changed');
        assert.equal(courierRows()[1].text, `Спиди — ${T('ck_kind_office')} — ${fmt(420)}`);
        assert.equal(courierRows()[1].radio.checked, true, 'the preference is kept');
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);
        assert.equal(form.elements.pickup_point_reference.value, 'S-1', 'what the customer typed stays');
        assert.equal(checkoutPosts().length, 1, 'NOTHING is resent until the customer presses again');
        await flush();
        assert.equal(checkoutPosts().length, 1);
        assert.equal(quotePosts().length - quotes, 1, 'nothing loops');

        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        await submit();
        assert.equal(checkoutPosts().length, 2);
        const body = lastCheckout().body;
        assert.equal(body.expected_shipping_minor, 420, 'the second press confirms the new figure');
        assert.equal(body.quote_handle, 'h6-office-2', 'and carries the NEW handle');
        assert.equal(body.shipping_method_id, '6');
        assert.equal(body.pickup_point_kind, 'office');
        assert.equal(q('[data-sf-checkout-status]').getAttribute('data-state'), null);
    },

    // The fresh quote disagrees with the price the refusal carried: the screen shows the latest figure, ONE message line, still no resubmit.
    async rule_a_requote_shows_yet_another_price() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[1].radio);
        fillContact();
        fillPoint('S-1');
        const quotes = quotePosts().length;

        priceOf = { '6:office': 480 };
        generation = 2;
        checkoutResponse = { status: 409, data: { message: 'The shipping price changed.', reason: 'shipping_price_changed', price: money(420) } };
        await submit();

        assert.equal(quotePosts().length - quotes, 1);
        assert.equal(checkoutPosts().length, 1, 'no automatic resubmit');
        assert.equal(courierRows()[1].text, `Спиди — ${T('ck_kind_office')} — ${fmt(480)}`, 'the latest figure');
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2480));
        assert.equal(q('[data-sf-checkout-status]').textContent, T('ck_price_changed').replace(':price', fmt(480)), 'one line, for the latest figure');
        assert.equal(placeOrder.disabled, false);

        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        await submit();
        assert.equal(lastCheckout().body.expected_shipping_minor, 480);
        assert.equal(lastCheckout().body.quote_handle, 'h6-office-2');
    },

    // An expired handle: dropped, a fresh quote re-captures the same (method, kind), the customer presses again.
    async quote_expired_requote() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        chooseKind('4:office');
        fillContact();
        fillPoint('E-1');
        const posts = quotePosts().length;

        checkoutResponse = { status: 409, data: { message: 'The delivery quote has expired.', reason: 'shipping_quote_expired' } };
        generation = 2;
        await submit();

        assert.equal(checkoutPosts().length, 1);
        assert.equal(quotePosts().length - posts, 1, 'one fresh quote');
        assert.match(q('[data-sf-error-summary]').textContent, /The delivery quote has expired\./);
        assert.equal(courierRows()[0].radio.checked, true, 'the preference is kept');
        assert.equal(kindSelect.value, '4:office');
        assert.equal(form.elements.pickup_point_reference.value, 'E-1');
        assert.equal(placeOrder.disabled, false);
        await flush();
        assert.equal(checkoutPosts().length, 1, 'no automatic resubmit');

        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        await submit();
        assert.equal(lastCheckout().body.quote_handle, 'h4-office-2', 'the handle of the NEW quote');
    },

    // The destination changes: the (method, kind) stays while the new quote still offers it (new handle); the button waits.
    async requote_keeps_choice() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        chooseKind('4:office');
        assert.equal(placeOrder.disabled, true, 'the point is still to type');
        fillContact();
        fillPoint('R-12');
        assert.equal(placeOrder.disabled, false);

        generation = 2;
        form.elements.city.value = 'Plovdiv';
        form.elements.city.dispatch('input');
        assert.equal(placeOrder.disabled, true, 'locked while the new quote is pending');
        await flush();

        assert.equal(placeOrder.disabled, false);
        assert.equal(courierRows()[0].radio.checked, true);
        assert.equal(kindSelect.value, '4:office');
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);
        assert.equal(form.elements.pickup_point_reference.value, 'R-12', 'a re-quote does not drop the typed point');

        await submit();

        assert.equal(lastCheckout().body.quote_handle, 'h4-office-2', 'the handle of the NEW quote');
        assert.equal(lastCheckout().body.settlement, 'Plovdiv');
    },

    // A (method, kind) that disappears from the new answer is cleared with a short message; the courier stays while still offered.
    async requote_clears_vanished_choice() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        chooseKind('4:office');

        dropped = [4];
        await typeInto('city', 'Varna');

        assert.equal(status(), T('ck_choice_gone'));
        assert.equal(placeOrder.disabled, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(courierRows()[0].radio.checked, true, 'the courier is still offered, so it stays');
        assert.equal(kindSelect.value, '', 'the kind is asked again; nothing was swapped silently');

        // The whole courier gone: cleared as well.
        chooseKind('1:address');
        assert.equal(placeOrder.disabled, false);
        dropped = [1, 4, 5];
        await typeInto('city', 'Ruse');
        assert.equal(status(), T('ck_choice_gone'));
        assert.deepEqual(courierRows().map((r) => r.radio.checked), [false, false]);
        assert.equal(q('[data-sf-type-box]').hidden, true);
    },

    // A kind the API says is unavailable is NOT offered; a courier with nothing available is listed disabled.
    async unavailable_kinds_are_not_offered() {
        unavailable = ['5:locker'];
        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        assert.deepEqual(kindOptions().map((o) => o.value), ['', '1:address', '4:office']);

        unavailable = ['6:office'];
        await typeInto('city', 'Varna');
        assert.equal(courierRows()[1].text, `Спиди — ${T('ck_unavailable')}`);
        assert.equal(courierRows()[1].radio.disabled, true);
    },

    // A courier-less method is the group "Other"; it is an address method here and sends no carrier_code.
    async courierless_method() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);

        assert.equal(kindSelect.hidden, true);
        assert.equal(q('[data-sf-address-fields]').hidden, false);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);

        fillContact();
        set('address_line_1', '5 Long St');
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.delivery_type, 'street_address');
        assert.equal(body.city, 'Sofia');
        assert.equal(body.address_line_1, '5 Long St');
        assert.equal(body.shipping_method_id, '2');
        assert.equal(body.quote_handle, 'h2-address-1');
        for (const absent of ['carrier_code', 'settlement', 'pickup_point_kind', 'region', 'customer_note']) {
            assert.equal(absent in body, false, `${absent} is not sent`);
        }
    },

    // The region follows the policy of the CURRENT country (the page is rendered with BG optional, DE required, FR hidden).
    async region_modes() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        const box = q('[data-sf-region-field]');
        const region = form.elements.region;

        // optional (BG, the default): shown, not required, no marker.
        assert.equal(box.hidden, false);
        assert.equal(region.required, false);
        assert.equal(box.getAttribute('data-required'), null);
        set('region', 'Sofia-city');

        // hidden (FR): gone, cleared, and not sent; a 422 on the note shows under the note.
        await typeInto('country', 'fr');
        assert.equal(box.hidden, true);
        assert.equal(region.value, '', 'a hidden region is cleared');
        checkoutResponse = { status: 422, data: { message: 'The given data was invalid.', errors: { customer_note: ['The note is not valid.'] } } };
        set('customer_note', 'hello');
        await submit();
        assert.equal(checkoutPosts().length, 1);
        assert.equal('region' in lastCheckout().body, false, 'a hidden region is not sent');
        assert.equal(q('[data-sf-error-for="customer_note"]').textContent, 'The note is not valid.');
        assert.equal(q('[data-sf-error-for="customer_note"]').hidden, false);

        // required (DE): shown with the marker; empty is blocked on the client with a message under the field.
        await typeInto('country', 'DE');
        assert.equal(box.hidden, false);
        assert.equal(region.required, true);
        assert.equal(box.getAttribute('data-required'), 'true');
        await submit();
        assert.equal(checkoutPosts().length, 1, 'blocked before any request');
        assert.equal(q('[data-sf-error-for="region"]').textContent, T('ck_region_required'));
        assert.equal(region.getAttribute('aria-invalid'), 'true');

        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        set('region', 'Bavaria');
        await submit();
        assert.equal(checkoutPosts().length, 2);
        assert.equal(lastCheckout().body.region, 'Bavaria');
        assert.equal(lastCheckout().body.country, 'DE');
    },

    // The note counts CODE POINTS (an emoji is one), warns at 90 %, never exceeds 1000, and an empty note is not sent.
    async note_counter() {
        const note = form.elements.customer_note;
        const counter = q('[data-sf-note-counter]');
        const type = (value) => { note.value = value; note.dispatch('input'); };

        type('😀😀😀');
        assert.equal(counter.textContent, '3 / 1000', 'three emoji are three, not six');
        assert.equal(counter.getAttribute('data-state'), null);

        type('a'.repeat(899));
        assert.equal(counter.textContent, '899 / 1000');
        assert.equal(counter.getAttribute('data-state'), null);
        type('a'.repeat(899) + '😀');
        assert.equal(counter.textContent, '900 / 1000');
        assert.equal(counter.getAttribute('data-state'), 'warning', 'warning from 90 %');
        type('b');
        assert.equal(counter.getAttribute('data-state'), null);

        type('x'.repeat(1002));
        assert.equal(note.value.length, 1000, 'the limit is respected');
        assert.equal(counter.textContent, '1000 / 1000');
        type('😀'.repeat(1001));
        assert.equal(Array.from(note.value).length, 1000);

        // Sent as typed; empty or blank is omitted.
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        type('   ');
        checkoutResponse = { status: 422, data: { errors: { email: ['no'] } } };
        await submit();
        assert.equal('customer_note' in lastCheckout().body, false, 'a blank note is omitted');
        type('');
        await submit();
        assert.equal('customer_note' in lastCheckout().body, false, 'an empty note is omitted');
        type(' Line one\nLine 😀 two ');
        await submit();
        assert.equal(lastCheckout().body.customer_note, ' Line one\nLine 😀 two ', 'sent as typed (the server trims)');
    },

    // The pure region rule against the table of cases the PHP test computed with the real CheckoutRegionPolicy.
    async region_agreement() {
        const cases = JSON.parse(readFileSync(extraPath, 'utf8'));
        assert.ok(cases.length > 10);

        for (const c of cases) {
            assert.equal(checkoutModule.regionModeFor(c.policy, c.country), c.expected, JSON.stringify(c));
        }
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
