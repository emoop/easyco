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

// ------------------------------------------------------------------------------------------------ the environment

const [, , htmlPath, scenario, extraPath] = process.argv;
const dom = parse(readFileSync(htmlPath, 'utf8'));
const calls = [];
const replaced = [];
let generation = 1;
let dropped = [];
let unavailable = [];
let priceOf = {};
let promo = null;          // the code applied to the scripted cart: {code, valid, discount}
let promoReply = null;     // a scripted refusal of PUT/DELETE /api/cart/promotion
let promoDelay = 0;
let promoValid = true;
let checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };

globalThis.document = {
    cookie: 'easyco_has_cart=1; XSRF-TOKEN=tok',
    getElementById: (id) => dom.querySelector(`#${id}`),
    querySelector: (s) => dom.querySelector(s),
    querySelectorAll: (s) => dom.querySelectorAll(s),
    createElement: (tag) => new FakeNode(tag),
};
const windowListeners = {};
globalThis.window = {
    innerWidth: 1280,
    innerHeight: 800,
    pageYOffset: 0,
    addEventListener: (type, fn) => { (windowListeners[type] ||= []).push(fn); },
    location: { href: 'http://shop.test/checkout', origin: 'http://shop.test', replace: (u) => replaced.push(u) },
    setTimeout: (fn) => setTimeout(fn, 0),
    clearTimeout,
};

const money = (minor) => ({ minor, currency: 'EUR' });
const fmt = (minor) => `${(minor / 100).toFixed(2)} €`;
const baseCart = {
    cart_id: 11,
    lines: [{ sku: 'S1', product_name: 'Dress', quantity: 2, attributes: [{ value: 'Red' }, { value: 'M' }], line_total: money(2000), image: null }],
    subtotal: money(2000),
};
// The scripted cart follows the applied code: the discount comes off the goods, the API's own figures.
const currentCart = () => ({
    ...baseCart,
    promotion: promo === null ? null : { code: promo.code, valid: promo.valid, discount_amount: promo.valid ? money(promo.discount) : null },
    total: money(2000 - (promo !== null && promo.valid ? promo.discount : 0)),
});

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
        cart_id: 11, currency: 'EUR', goods_after_discount: currentCart().total, mode: 'all', preview, methods: all.map((m) => ({ ...m, id: String(m.id) })), groups,
        free_shipping_hint: { text: 'Add 5 € more for free delivery', destinations: ['office', 'locker'] },
    };
}

globalThis.fetch = async (path, init = {}) => {
    const method_ = init.method || 'GET';
    const body = init.body ? JSON.parse(init.body) : undefined;
    calls.push({ method: method_, path, body });
    let result = { status: 404, data: null };

    if (method_ === 'GET' && path === '/api/cart') result = { status: 200, data: currentCart() };
    if (path === '/api/cart/promotion') {
        if (promoDelay > 0) await new Promise((resolve) => setTimeout(resolve, promoDelay));

        if (promoReply !== null) {
            result = promoReply;
        } else if (method_ === 'PUT') {
            promo = { code: body.code, valid: promoValid, discount: 200 };
            result = { status: 200, data: currentCart() };
        } else {
            promo = null;
            result = { status: 200, data: currentCart() };
        }
    }
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
const promoHost = () => q('[data-sf-promo]');
// Does the field's label carry the asterisk?
const markerOf = (name) => q(`label[for="${form.elements[name].id}"]`).querySelector('[aria-hidden="true"]') !== null;
const promoForm = () => promoHost().querySelector('form');
const promoInput = () => promoHost().querySelector('input');
const cartGets = () => calls.filter((c) => c.method === 'GET' && c.path === '/api/cart').length;

async function applyCode(code) {
    promoInput().value = code;
    const event = promoForm().dispatch('submit');
    await flush();

    return event;
}

async function removeCode() {
    promoHost().querySelectorAll('button')[0].dispatch('click');
    await flush();
}

// A pick-up order half typed: courier Speedy (one kind: office), contact, the point and a note.
async function halfTypedPickup() {
    await typeInto('city', 'Sofia');
    choose(courierRows()[1].radio);
    fillContact();
    fillPoint('S-1');
    set('customer_note', 'Please call');
    form.elements.customer_note.dispatch('input');
}

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
    set('recipient_first_name', 'Ana');
    set('recipient_last_name', 'Petrova');
    set('phone', '0888123456');
}

// ONE field: what the customer types is the office's address, name or code (checkout form v2, D3).
function fillPoint(code) {
    set('pickup_point_address', `${code} Main St`);
    PICKUP.forEach((n) => form.elements[n].dispatch('input'));
}

const PICKUP = ['pickup_point_address'];

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
        assert.equal(form.elements.recipient_name, undefined, 'the single name field is gone');
        assert.equal(form.elements.recipient_first_name.attrs.autocomplete, 'given-name');
        assert.equal(form.elements.recipient_last_name.attrs.autocomplete, 'family-name');
        assert.equal(form.elements.recipient_first_name.attrs.maxlength, '100');
        assert.equal(form.elements.recipient_last_name.attrs.maxlength, '100');
        assert.ok(q('[data-sf-error-for="recipient_first_name"]') && q('[data-sf-error-for="recipient_last_name"]'), 'own error holders');
        assert.equal(form.elements.pickup_point_reference, undefined, 'no typed code');
        assert.equal(form.elements.pickup_point_name, undefined, 'no typed name');
        assert.equal(q('[data-sf-pickup-fields]').all().filter((n) => n.tag === 'input').length, 1, 'ONE pick-up input');
        assert.equal(form.elements.pickup_point_address.attrs['aria-describedby'], 'sf-pickup-help', 'the help line is linked');
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
            cart_id: 11, email: 'a@example.com', recipient_first_name: 'Ana', recipient_last_name: 'Petrova', phone: '0888123456', payment_method: lastCheckout().body.payment_method,
            delivery_type: 'pickup_point', country: 'BG', shipping_method_id: '6', quote_handle: 'h6-office-1', expected_shipping_minor: 350,
            settlement: 'Sofia', carrier_code: 'Спиди', pickup_point_kind: 'office',
            pickup_point_address: 'S-1 Main St',
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
        assert.deepEqual(PICKUP.map((n) => form.elements[n].value), [''], 'the point belonged to the other courier');
        fillPoint('E-9');

        // Another kind of the same courier: the point belonged to the office: dropped again.
        chooseKind('5:locker');
        assert.deepEqual(PICKUP.map((n) => form.elements[n].value), ['']);
        assert.equal(placeOrder.disabled, true);
        fillPoint('L-3');
        assert.equal(placeOrder.disabled, false);

        // Everything else is untouched, the street fields included (an address would come back to them).
        assert.equal(form.elements.email.value, 'a@example.com');
        assert.equal(form.elements.recipient_first_name.value, 'Ana');
        assert.equal(form.elements.recipient_last_name.value, 'Petrova');
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
            cart_id: 11, email: 'a@example.com', recipient_first_name: 'Ana', recipient_last_name: 'Petrova', phone: '0888123456', payment_method: body.payment_method,
            delivery_type: 'pickup_point', country: 'BG', shipping_method_id: '5', quote_handle: 'h5-locker-1', expected_shipping_minor: 0,
            settlement: 'Sofia', carrier_code: 'Еконт', pickup_point_kind: 'locker',
            pickup_point_address: 'L-3 Main St', customer_note: 'Please call 😀',
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
        assert.equal(form.elements.pickup_point_address.value, 'S-1 Main St', 'what the customer typed stays');
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
        assert.equal(form.elements.pickup_point_address.value, 'E-1 Main St');
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
        assert.equal(form.elements.pickup_point_address.value, 'R-12 Main St', 'a re-quote does not drop the typed point');

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
        assert.equal(markerOf('region'), false, 'optional: no asterisk');
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
        assert.equal(markerOf('region'), true, 'required: the asterisk follows the rule');
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

    // The promo box lives in the summary (so in the mobile panel), above the totals, OUTSIDE the checkout form.
    async promo_box_in_summary() {
        const host = promoHost();
        const panel = q('#sf-summary-panel');
        const totals = q('[data-sf-summary-totals]');

        assert.ok(panel.all().includes(host), 'inside the summary panel (the mobile toggle opens that same panel)');
        assert.ok(! form.all().includes(host), 'not inside the checkout form');
        assert.ok(panel.all().indexOf(q('[data-sf-summary-lines]')) < panel.all().indexOf(host), 'below the item lines');
        assert.ok(panel.all().indexOf(host) < panel.all().indexOf(totals), 'above the totals');
        assert.equal(host.hidden, false, 'shown once the cart is loaded');
        assert.equal(promoInput().attrs.type, 'text');
        assert.equal(promoForm().tag, 'form');
        assert.notEqual(promoForm(), form);
        assert.equal(promoHost().querySelectorAll('button')[0].textContent, T('promo_apply'));
        assert.equal(promoHost().querySelectorAll('button')[0].attrs.type, 'submit');
    },

    // Applying a code: the cart is reloaded, ONE fresh `all` quote, the choice kept, everything typed untouched, the discount in the totals.
    async promo_apply_requotes_and_keeps_choice() {
        await halfTypedPickup();
        const carts = cartGets();
        const quotes = quotePosts().length;

        generation = 2;
        await applyCode('SAVE10');

        assert.equal(cartGets() - carts, 1, 'the cart is reloaded');
        assert.equal(quotePosts().length - quotes, 1, 'exactly ONE new quote');
        assert.ok(q('[data-sf-summary-totals]').textContent.includes(fmt(-200)), 'the discount row');
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2150), 'goods after the discount + delivery');
        assert.equal(courierRows()[1].radio.checked, true, 'the (courier, method, kind) is kept');
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);
        assert.deepEqual(PICKUP.map((n) => form.elements[n].value), ['S-1 Main St'], 'the point stays');
        assert.equal(form.elements.customer_note.value, 'Please call');
        assert.equal(form.elements.email.value, 'a@example.com');
        assert.equal(placeOrder.disabled, false);
        assert.equal(checkoutPosts().length, 0, 'nothing is submitted');
        assert.equal(promoHost().textContent.includes(T('promo_applied').replace(':code', 'SAVE10')), true);

        await submit();
        assert.equal(lastCheckout().body.quote_handle, 'h6-office-2', 'the handle of the NEW quote');
    },

    // Rule (A) on the screen: the re-quote prices the kept choice differently: the new figure and the announcement, nothing submitted.
    async promo_changes_delivery_price_rule_a() {
        await halfTypedPickup();

        priceOf = { '6:office': 450 };
        await applyCode('SAVE10');

        assert.equal(courierRows()[1].text, `Спиди — ${T('ck_kind_office')} — ${fmt(450)}`);
        assert.equal(q('[data-sf-checkout-status]').textContent, T('ck_price_changed').replace(':price', fmt(450)));
        assert.equal(q('[data-sf-checkout-status]').getAttribute('data-state'), 'price-changed');
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(1800 + 450));
        assert.equal(checkoutPosts().length, 0);
        assert.equal(placeOrder.disabled, false);
    },

    async promo_remove_requotes() {
        await halfTypedPickup();
        await applyCode('SAVE10');
        const carts = cartGets();
        const quotes = quotePosts().length;

        await removeCode();

        assert.equal(cartGets() - carts, 1);
        assert.equal(quotePosts().length - quotes, 1);
        assert.equal(q('[data-sf-summary-totals]').textContent.includes(fmt(-200)), false, 'the discount row is gone');
        assert.equal(q('[data-sf-summary-toggle-total]').textContent, fmt(2350));
        assert.equal(promoForm().tag, 'form', 'the apply form is back');
        assert.equal(courierRows()[1].radio.checked, true);
        assert.equal(form.elements.pickup_point_address.value, 'S-1 Main St');
        assert.equal(checkoutPosts().length, 0);
    },

    // An invalid code shows its message and a Remove button; an API refusal shows under the field and the box is usable again.
    async promo_invalid_and_error() {
        const quotes = quotePosts().length;

        promoReply = { status: 422, data: { message: 'This code is not valid.' } };
        await applyCode('NOPE');
        assert.equal(q('[data-sf-promo-error]').textContent, 'This code is not valid.');
        assert.equal(q('[data-sf-promo-error]').hidden, false);
        assert.equal(promoHost().querySelectorAll('button')[0].disabled, false, 'usable again');
        assert.equal(promoInput().disabled, false);
        assert.equal(quotePosts().length, quotes, 'a refused code re-quotes nothing');

        promoReply = { status: 429, data: { message: 'Too many attempts.' } };
        await applyCode('NOPE');
        assert.equal(q('[data-sf-promo-error]').textContent, 'Too many attempts.');

        promoReply = { status: 500, data: null };
        await applyCode('NOPE');
        assert.equal(q('[data-sf-promo-error]').textContent, T('generic_error'));

        // A code the cart holds but can no longer use: the message and Remove.
        promoReply = null;
        promoValid = false;
        await applyCode('OLD');
        assert.equal(q('[data-sf-promo-error]').hidden, true, 'the earlier error is cleared');
        assert.ok(promoHost().textContent.includes(T('promo_invalid').replace(':code', 'OLD')));
        assert.equal(promoHost().querySelectorAll('button')[0].textContent, T('promo_remove'));
    },

    // Enter in the code field applies the code; it never reaches the checkout form.
    async promo_does_not_submit_the_form() {
        await halfTypedPickup();

        const event = await applyCode('SAVE10');

        assert.equal(event.defaultPrevented, true, 'the browser does not post the promo form');
        assert.equal(checkoutPosts().length, 0, 'no order was placed');
        assert.equal(replaced.length, 0);
        assert.ok(! form.all().includes(promoForm()), 'the promo form is not part of the checkout form');
        assert.equal(calls.filter((c) => c.path === '/api/cart/promotion' && c.method === 'PUT').length, 1);
    },

    // While the promo request runs the box and "Place order" are locked; afterwards everything is as before.
    async promo_busy_locks_place_order() {
        await halfTypedPickup();
        assert.equal(placeOrder.disabled, false);

        promoDelay = 80;
        promoInput().value = 'SAVE10';
        promoForm().dispatch('submit');
        await new Promise((resolve) => setTimeout(resolve, 10));

        assert.equal(placeOrder.disabled, true, 'locked while the promo request runs');
        assert.equal(promoHost().querySelectorAll('button')[0].disabled, true);
        assert.equal(promoInput().disabled, true);

        await new Promise((resolve) => setTimeout(resolve, 300));
        assert.equal(placeOrder.disabled, false, 'free again');
        assert.equal(promoHost().querySelectorAll('button')[0].disabled, false);
    },

    // The asterisk follows `required`: always-required fields carry it from the server, the others get it from the SAME state that
    // toggles `required` (street fields by kind, the pick-up field by kind, the region by the country's policy).
    async required_markers() {
        const named = ['email', 'recipient_first_name', 'recipient_last_name', 'phone', 'country', 'city', 'postal_code', 'address_line_1', 'address_line_2', 'region', 'pickup_point_address', 'customer_note'];
        const parity = (why) => {
            for (const name of named) {
                assert.equal(markerOf(name), form.elements[name].required === true, `${why}: ${name} marker <-> required`);
                assert.equal(form.elements[name].getAttribute('aria-required'), form.elements[name].required ? 'true' : null, `${why}: ${name} aria-required`);
            }
        };

        assert.match(form.all().find((n) => n.className === 'sf-required-note').textContent, /\*/, 'the explanatory line');
        for (const name of ['email', 'recipient_first_name', 'recipient_last_name', 'phone', 'country', 'city']) {
            assert.equal(markerOf(name), true, `${name} is always required`);
        }
        parity('before any choice');

        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);            // the courier-less ADDRESS method
        assert.equal(markerOf('address_line_1'), true, 'a street address needs the street line');
        assert.equal(markerOf('pickup_point_address'), false);
        assert.equal(markerOf('region'), false, 'BG: optional');
        parity('address, BG');

        await typeInto('country', 'DE');           // required by the policy the page was rendered with
        assert.equal(markerOf('region'), true);
        parity('address, DE');
        await typeInto('country', 'FR');           // hidden
        assert.equal(markerOf('region'), false);
        parity('address, FR');
        await typeInto('country', 'BG');

        choose(courierRows()[1].radio);            // Speedy: one kind, an office
        assert.equal(markerOf('pickup_point_address'), true, 'a pick-up point needs its field');
        assert.equal(markerOf('address_line_1'), false, 'and no longer the street line');
        parity('pick-up');
    },

    // The checkout's compact promo box: ONE control (the field with the Apply button attached), the label kept for screen readers.
    async promo_compact_box() {
        const box = promoHost().querySelector('div');
        const input = promoInput();
        const form_ = promoForm();
        const label = form_.querySelector('label');

        assert.equal(input.attrs.placeholder, T('promo_label'), 'the promo text is the placeholder');
        assert.equal(label.attrs.for, input.id, 'a real label stays, for screen readers');
        assert.equal(label.textContent, T('promo_label'));
        assert.match(label.className, /sf-promo-compact-label/, 'visually hidden by its class');
        const wrapper = input.parent;
        assert.match(wrapper.className, /sf-promo-compact-field/);
        assert.ok(wrapper.children.includes(promoHost().querySelectorAll('button')[0]), 'the button is in the SAME wrapper as the input');
        assert.equal(wrapper.children.indexOf(input) < wrapper.children.indexOf(promoHost().querySelectorAll('button')[0]), true, 'the button sits at the right end of the field');
        assert.match(box.className, /sf-promo-compact/);

        // The applied state works in the compact mode too.
        await applyCode('SAVE10');
        assert.ok(promoHost().textContent.includes(T('promo_applied').replace(':code', 'SAVE10')));
        assert.equal(promoHost().querySelectorAll('button')[0].textContent, T('promo_remove'));
    },

    // The CART page's box is exactly what it always was: the shared function called WITHOUT options builds this structure.
    async promo_cart_box_unchanged() {
        const cartModule = await import(new URL('../../../../resources/js/storefront-cart.js', import.meta.url));
        const shape = (node) => (node.tag === '#text' ? node.data : `${node.tag}${node.className ? '.' + node.className.split(' ').join('.') : ''}`
            + Object.keys(node.attrs).filter((k) => k !== 'class').sort().map((k) => `[${k}=${node.attrs[k]}]`).join('')
            + (node.children.length ? `(${node.children.map(shape).join(',')})` : ''));

        const empty = cartModule.promotionBox({ promotion: null }, () => {});
        assert.equal(shape(empty), [
            'div.sf-cart-promo(form.sf-cart-promo-form(',
            `label.sf-cart-line-caption[for=sf-promo-code](${T('promo_label')}),`,
            'input.sf-cart-promo-input[autocapitalize=characters][autocomplete=off][id=sf-promo-code][maxlength=64][type=text],',
            `button.sf-cart-promo-apply[type=submit](${T('promo_apply')})))`,
        ].join(''));

        const applied = cartModule.promotionBox({ promotion: { code: 'X', valid: true } }, () => {});
        assert.equal(shape(applied), `div.sf-cart-promo(p.sf-cart-promo-applied(${T('promo_applied').replace(':code', 'X')}),button.sf-cart-promo-remove[type=button](${T('promo_remove')}))`);

        const invalid = cartModule.promotionBox({ promotion: { code: 'X', valid: false } }, () => {});
        assert.equal(shape(invalid), `div.sf-cart-promo(p.sf-cart-promo-invalid(${T('promo_invalid').replace(':code', 'X')}),button.sf-cart-promo-remove[type=button](${T('promo_remove')}))`);
    },

    // ---- checkout form v2, stage B: the country select, the optional choices and the consent, the payment label, the sticky summary ----

    // The country is a native <select>: its `change` event re-asks the quote and re-evaluates the region rule, exactly as typing did.
    async country_select_change_requotes() {
        const select = form.elements.country;
        assert.equal(select.tag, 'select');
        assert.equal(select.value, 'BG', 'the store country is preselected');
        assert.deepEqual(quotePosts()[0].body, { delivery_type: 'all', country: 'BG' }, 'the preview used the preselected country');
        const before = quotePosts().length;

        select.value = 'DE';
        select.dispatch('change');
        await flush();

        assert.equal(quotePosts().length - before, 1, 'one new request for the new country');
        assert.equal(quotePosts().at(-1).body.country, 'DE');
        assert.equal(markerOf('region'), false, 'the region marker needs a street address first');
        await typeInto('city', 'Berlin');
        choose(courierRows()[2].radio);
        assert.equal(markerOf('region'), true, 'DE: the region is required, decided by the selected value');
    },

    // The boxes the merchant turned on are sent as what they are (false when not ticked); the terms carry the version the page showed.
    async choices_in_payload() {
        assert.equal(form.elements.marketing_consent.checked, false, 'never ticked in advance');
        assert.equal(form.elements.call_before_shipping.checked, false);
        assert.equal(form.elements.terms_accepted.checked, false);

        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        form.elements.terms_accepted.checked = true;
        form.elements.call_before_shipping.checked = true;
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.terms_accepted, true);
        assert.equal(body.terms_version, '2026-10-14');
        assert.equal(body.call_before_shipping, true);
        assert.equal(body.marketing_consent, false, 'offered and not ticked is false, not absent');
        assert.equal('marketing_consent_text' in body, false, 'the sentence is the server\'s');
        assert.equal('confirmation_requested' in body, false, 'the storefront never asks it');
    },

    async terms_unticked_blocks_the_submit() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');

        await submit();

        assert.equal(checkoutPosts().length, 0, 'blocked before any request');
        assert.equal(q('[data-sf-error-for="terms_accepted"]').textContent, T('ck_terms_required'));
        assert.equal(q('[data-sf-error-for="terms_accepted"]').hidden, false);
        assert.equal(form.elements.terms_accepted.getAttribute('aria-invalid'), 'true');
        assert.match(q('[data-sf-error-summary]').textContent, new RegExp(T('ck_terms_required')));
    },

    // The terms changed under the open page: said, the box cleared, the order locked until the page is reloaded; nothing is resent.
    async terms_version_changed_locks_the_order() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        form.elements.terms_accepted.checked = true;
        checkoutResponse = { status: 422, data: { message: 'The terms changed while you were filling in the order.', reason: 'terms_version_changed' } };

        await submit();

        assert.equal(checkoutPosts().length, 1);
        assert.match(q('[data-sf-error-summary]').textContent, /The terms changed/);
        assert.match(q('[data-sf-error-summary]').textContent, new RegExp(T('ck_terms_reload')));
        assert.equal(form.elements.terms_accepted.checked, false, 'the acceptance is not kept');
        assert.equal(placeOrder.disabled, true, 'locked until the page is reloaded');
        await flush();
        await submit();
        assert.equal(checkoutPosts().length, 1, 'never resubmitted, by itself or by a second press');
    },

    // The button's label is the page's own until a payment method with its own is chosen; back again when another is.
    async payment_label_follows_the_chosen_method() {
        const radios = form.all().filter((n) => n.tag === 'input' && n.name === 'payment_method');
        const own = radios.find((r) => r.attrs['data-place-order-label']);
        const plain = radios.find((r) => ! r.attrs['data-place-order-label']);

        assert.ok(own && plain, 'one method with its own label, one without');
        assert.equal(own.checked, true, 'the first card is preselected');
        assert.equal(placeOrder.textContent, own.attrs['data-place-order-label']);

        choose(plain);
        assert.equal(placeOrder.textContent, 'Place order', 'the own label of the page (the server-rendered one)');

        choose(own);
        assert.equal(placeOrder.textContent, own.attrs['data-place-order-label']);
    },

    // The sticky summary: its top is measured, never smaller than the header + 8px and at most 24px above its natural place.
    async summary_top_is_measured() {
        const header = dom.querySelector('header');
        const layoutNode = q('[data-sf-checkout-layout]');
        const panel = q('#sf-summary-panel');
        const lines = q('[data-sf-summary-lines]');
        const resize = async () => { windowListeners.resize.forEach((fn) => fn()); await flush(); };

        header.rect = { top: 0, height: 64 };
        layoutNode.rect = { top: 220, height: 900 };
        panel.rect = { top: 0, height: 1200 };
        lines.rect = { top: 0, height: 900 };
        await resize();
        assert.equal(panel.style.getPropertyValue('--sf-summary-top'), '196px', 'natural top 220 - 24');
        assert.equal(lines.style.getPropertyValue('max-height'), '288px', 'taller than 800 - 196 - 16: only the item list scrolls');
        assert.equal(lines.style.getPropertyValue('overflow-y'), 'auto');

        layoutNode.rect = { top: 60, height: 900 };
        panel.rect = { top: 0, height: 400 };
        await resize();
        assert.equal(panel.style.getPropertyValue('--sf-summary-top'), '72px', 'never under the header: 64 + 8');
        assert.equal(lines.style.getPropertyValue('max-height'), '', 'fits: nothing scrolls');

        globalThis.window.innerWidth = 800;
        await resize();
        assert.equal(panel.style.getPropertyValue('--sf-summary-top'), '', 'the mobile panel is left alone');
        globalThis.window.innerWidth = 1280;
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
    // ------------------------------------------------------------------------------------------------------------------ the invoice
    // (order-context-design.md 9.3, stage C2-A) The page is rendered with the rules BG ("ЕИК / Булстат", required, МОЛ) and DE (the registration
    // number required, labelled "USt-IdNr"). The whole block is HIDDEN until its box is ticked.

    // Hidden by default: nothing in it is required, none of it is sent, and an order can be placed with every invoice field empty.
    async invoice_hidden_by_default() {
        const toggle = form.elements.invoice_requested;
        const block = q('[data-sf-invoice-block]');

        assert.ok(toggle, 'the box is there (the merchant offers invoices)');
        assert.equal(toggle.checked, false, 'never ticked in advance');
        assert.equal(block.hidden, true, 'the whole block is hidden');
        assert.equal(form.elements.invoice_recipient_type.checked, true, 'a company is the chosen type (a choice, not a consent)');

        const names = ['invoice_legal_name', 'invoice_tax_id', 'invoice_registration_number', 'invoice_representative', 'invoice_country', 'invoice_city', 'invoice_postal_code', 'invoice_address_line_1', 'invoice_address_line_2', 'invoice_email'];

        names.forEach((name) => {
            assert.ok(form.elements[name], `${name} exists`);
            assert.equal(form.elements[name].required, false, `${name} is not required while hidden`);
            assert.equal('required' in form.elements[name].attrs, false, `${name} has no required attribute while hidden`);
            assert.equal(markerOf(name), false, `${name} shows no asterisk while hidden`);
        });

        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        await submit();

        assert.equal(checkoutPosts().length, 1, 'the form submits with every invoice field empty');
        assert.equal('invoice' in lastCheckout().body, false, 'and no invoice key is sent');
    },

    // Ticked: the block shows, the marked fields are the required ones, a company's tax id follows the rule of the INVOICE country, and an
    // `invoice` object is sent.
    async invoice_shown_when_ticked() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');

        const toggle = form.elements.invoice_requested;
        toggle.checked = true;
        toggle.dispatch('change');

        assert.equal(q('[data-sf-invoice-block]').hidden, false, 'the block is visible');
        assert.equal(q('[data-sf-invoice-company]').hidden, false, 'a company is asked for its tax id, registration number and representative');

        ['invoice_legal_name', 'invoice_country', 'invoice_city', 'invoice_postal_code', 'invoice_address_line_1', 'invoice_tax_id'].forEach((name) => {
            assert.equal(form.elements[name].required, true, `${name} is required`);
            assert.equal(form.elements[name].getAttribute('aria-required'), 'true');
            assert.equal(markerOf(name), true, `${name} carries the asterisk`);
        });

        ['invoice_registration_number', 'invoice_representative', 'invoice_address_line_2', 'invoice_email'].forEach((name) => {
            assert.equal(form.elements[name].required, false, `${name} stays optional`);
            assert.equal(markerOf(name), false);
        });

        assert.equal(form.elements.invoice_country.value, 'BG', 'the invoice country starts as the delivery country');
        assert.equal(form.elements.invoice_email.getAttribute('placeholder'), 'a@example.com', 'the placeholder is the order email');
        assert.equal(q('label[for="sf-invoice-tax-id"]').textContent.replace('*', '').trim(), 'ЕИК / Булстат', 'the label of the BG rule');
        assert.equal(q('label[for="sf-invoice-representative"]').textContent.trim(), 'МОЛ');

        set('invoice_legal_name', 'Acme OOD');
        set('invoice_tax_id', '123456789');
        set('invoice_representative', 'Ivan Ivanov');
        set('invoice_city', 'Sofia');
        set('invoice_postal_code', '1000');
        set('invoice_address_line_1', '1 Main St');
        await submit();

        assert.equal(checkoutPosts().length, 1);
        assert.deepEqual(lastCheckout().body.invoice, {
            recipient_type: 'company', legal_name: 'Acme OOD', country: 'BG', city: 'Sofia', postal_code: '1000', address_line_1: '1 Main St',
            tax_id: '123456789', representative: 'Ivan Ivanov',
        });
    },

    // An individual: no tax id, registration number or representative is asked, and none is ever sent, even when typed before switching.
    async invoice_individual_sends_no_company_fields() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');

        form.elements.invoice_requested.checked = true;
        form.elements.invoice_requested.dispatch('change');
        set('invoice_tax_id', '123456789');
        set('invoice_registration_number', 'R-1');

        choose(form.all().find((n) => n.name === 'invoice_recipient_type' && n.attrs.value === 'individual'));

        assert.equal(q('[data-sf-invoice-company]').hidden, true, 'the company fields are hidden');
        assert.equal(form.elements.invoice_tax_id.required, false, 'and not required');
        assert.equal(markerOf('invoice_tax_id'), false);
        assert.equal(q('label[for="sf-invoice-legal-name"]').textContent.replace('*', '').trim(), 'Full name', 'the name label is the person one');
        assert.equal(form.elements.invoice_legal_name.required, true, 'the name is still required');

        set('invoice_legal_name', 'Ivan Ivanov');
        set('invoice_city', 'Sofia');
        set('invoice_postal_code', '1000');
        set('invoice_address_line_1', '1 Main St');
        await submit();

        const invoice = lastCheckout().body.invoice;
        assert.equal(invoice.recipient_type, 'individual');
        ['tax_id', 'company_registration_number', 'representative'].forEach((key) => assert.equal(key in invoice, false, `${key} is never sent for an individual`));
    },

    // The rule is the INVOICE country's: DE labels the registration number "USt-IdNr" and requires it; back to BG, it is optional again.
    async invoice_rules_follow_the_invoice_country() {
        form.elements.invoice_requested.checked = true;
        form.elements.invoice_requested.dispatch('change');

        assert.equal(form.elements.invoice_registration_number.required, false);

        form.elements.invoice_country.value = 'DE';
        form.elements.invoice_country.dispatch('change');

        assert.equal(form.elements.invoice_registration_number.required, true, 'DE requires it');
        assert.equal(markerOf('invoice_registration_number'), true);
        assert.equal(q('label[for="sf-invoice-registration"]').textContent.replace('*', '').trim(), 'USt-IdNr');
        assert.equal(form.elements.invoice_tax_id.required, false, 'DE says a company need not give a tax number');
        assert.equal(q('label[for="sf-invoice-tax-id"]').textContent.trim(), q('label[for="sf-invoice-tax-id"]').querySelector('[data-sf-label-text]').getAttribute('data-label-default'), 'the generic label returns for a rule with no label');

        form.elements.invoice_country.value = 'FR';
        form.elements.invoice_country.dispatch('change');

        assert.equal(form.elements.invoice_tax_id.required, true, 'a country with no rule: the generic rule requires a company tax id');
        assert.equal(form.elements.invoice_registration_number.required, false);
    },

    // The invoice country follows the delivery country until the customer chooses one herself.
    async invoice_country_defaults_to_the_delivery_country() {
        form.elements.invoice_requested.checked = true;
        form.elements.invoice_requested.dispatch('change');
        assert.equal(form.elements.invoice_country.value, 'BG');

        form.elements.country.value = 'DE';
        form.elements.country.dispatch('change');
        assert.equal(form.elements.invoice_country.value, 'DE', 'followed the delivery country');

        form.elements.invoice_country.value = 'FR';
        form.elements.invoice_country.dispatch('change');
        form.elements.country.value = 'BG';
        form.elements.country.dispatch('change');
        assert.equal(form.elements.invoice_country.value, 'FR', 'her own choice is kept');
    },

    // "Same as the delivery address": only for a street delivery, and it copies the street fields.
    async invoice_copy_button_copies_the_street_fields() {
        const copy = q('[data-sf-invoice-copy]');

        form.elements.invoice_requested.checked = true;
        form.elements.invoice_requested.dispatch('change');
        assert.equal(copy.hidden, true, 'no delivery chosen yet: no button');

        await typeInto('city', 'Plovdiv');
        choose(courierRows()[1].radio);   // Speedy: a pick-up point
        assert.equal(copy.hidden, true, 'a pick-up delivery has no street address to copy');

        choose(courierRows()[2].radio);   // the courier-less address method
        assert.equal(copy.hidden, false, 'a street delivery shows it');

        set('postal_code', '4000');
        set('address_line_1', '7 River St');
        set('address_line_2', 'Floor 2');
        form.elements.country.value = 'DE';
        copy.dispatch('click');

        assert.equal(form.elements.invoice_country.value, 'DE');
        assert.equal(form.elements.invoice_city.value, 'Plovdiv');
        assert.equal(form.elements.invoice_postal_code.value, '4000');
        assert.equal(form.elements.invoice_address_line_1.value, '7 River St');
        assert.equal(form.elements.invoice_address_line_2.value, 'Floor 2');
    },

    // The API's 422 on invoice.* lands under the right fields (the registration number's API name is `company_registration_number`).
    async invoice_errors_are_shown_under_the_right_fields() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        form.elements.invoice_requested.checked = true;
        form.elements.invoice_requested.dispatch('change');
        set('invoice_legal_name', 'Acme');
        set('invoice_tax_id', 'x');
        set('invoice_city', 'Sofia');
        set('invoice_postal_code', '1000');
        set('invoice_address_line_1', '1 Main St');

        checkoutResponse = {
            status: 422,
            data: { message: 'bad', reason: 'invoice_details_invalid', errors: { 'invoice.tax_id': ['"ЕИК / Булстат" is not in the expected format.'], 'invoice.company_registration_number': ['needed'], 'invoice.email': ['not an email'] } },
        };
        await submit();

        assert.equal(q('[data-sf-error-for="invoice_tax_id"]').textContent, '"ЕИК / Булстат" is not in the expected format.');
        assert.equal(q('[data-sf-error-for="invoice_tax_id"]').hidden, false);
        assert.equal(form.elements.invoice_tax_id.getAttribute('aria-invalid'), 'true');
        assert.equal(q('[data-sf-error-for="invoice_registration_number"]').textContent, 'needed');
        assert.equal(q('[data-sf-error-for="invoice_email"]').textContent, 'not an email');
        assert.match(q('[data-sf-error-summary]').textContent, /ЕИК/);
        assert.equal(q('[data-sf-error-for="invoice_legal_name"]').hidden, true, 'a field with no error stays quiet');
    },

    // Ticked, typed, then UNticked: the block hides, every requirement goes, and the typed values stay in the inputs but are not sent.
    async invoice_untick_removes_the_requirements_and_the_payload() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');

        const toggle = form.elements.invoice_requested;
        toggle.checked = true;
        toggle.dispatch('change');
        set('invoice_legal_name', 'Acme');
        set('invoice_tax_id', '123456789');
        assert.equal(form.elements.invoice_legal_name.required, true);

        toggle.checked = false;
        toggle.dispatch('change');

        assert.equal(q('[data-sf-invoice-block]').hidden, true);
        ['invoice_legal_name', 'invoice_tax_id', 'invoice_country', 'invoice_city', 'invoice_postal_code', 'invoice_address_line_1'].forEach((name) => {
            assert.equal(form.elements[name].required, false, `${name} is no longer required`);
            assert.equal(markerOf(name), false, `${name} lost its asterisk`);
        });
        assert.equal(form.elements.invoice_legal_name.value, 'Acme', 'what was typed stays in the input');

        await submit();

        assert.equal(checkoutPosts().length, 1);
        assert.equal('invoice' in lastCheckout().body, false, 'but it is not sent');
    },

    // ------------------------------------------------------------------------------------------------------------------ blocked payment
    // (cod-protection-design.md 4, stage C2-B) The API answers `payment_method_blocked` to a real attempt; there is no pre-check endpoint.

    // Another method is left: cash on delivery is marked unavailable and unchosen, the customer is told to choose another, and nothing is resent.
    async blocked_with_another_method() {
        const radios = form.all().filter((n) => n.tag === 'input' && n.name === 'payment_method');
        const cod = radios.find((r) => r.attrs.value === 'cash_on_delivery');
        const bank = radios.find((r) => r.attrs.value === 'bank_transfer');

        assert.equal(cod.attrs['data-blockable'], '1', 'cash on delivery is the blockable method');
        assert.equal('data-blockable' in bank.attrs, false, 'bank transfer is not');
        assert.equal(cod.checked, true, 'preselected, as before');

        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        checkoutResponse = { status: 422, data: { message: 'x', reason: 'payment_method_blocked' } };
        await submit();

        assert.equal(checkoutPosts().length, 1);
        assert.equal(cod.disabled, true, 'cash on delivery is unavailable');
        assert.equal(cod.checked, false, 'and not chosen');
        assert.equal(cod.attrs['data-state'], 'unavailable');
        assert.equal(bank.disabled, false, 'bank transfer is still there');
        assert.equal(bank.checked, false, 'selects nothing: the customer chooses');
        assert.equal(q('[data-sf-error-for="payment_method"]').textContent, T('ck_payment_unavailable'));
        assert.equal(q('[data-sf-error-for="payment_method"]').hidden, false);
        assert.match(q('[data-sf-error-summary]').textContent, new RegExp(T('ck_payment_unavailable')));
        assert.equal(placeOrder.disabled, true, 'nothing can be ordered until a method is chosen');
        assert.equal(T('ck_payment_unavailable').includes('block'), false, 'the page never says "blocked"');

        await submit();
        assert.equal(checkoutPosts().length, 1, 'never resubmitted by itself or by a second press');

        choose(bank);
        assert.equal(placeOrder.disabled, false, 'choosing the other method unlocks the order');
        assert.equal(q('[data-sf-error-for="payment_method"]').hidden, true, 'and clears the message');

        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        await submit();

        assert.equal(checkoutPosts().length, 2);
        assert.equal(lastCheckout().body.payment_method, 'bank_transfer');
    },

    // No other method: the order button is locked for good and the owner's own sentence is shown; there is nothing to choose.
    async blocked_without_another_method() {
        const radios = form.all().filter((n) => n.tag === 'input' && n.name === 'payment_method');
        assert.equal(radios.length, 1, 'the page was rendered with only cash on delivery');

        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        fillContact();
        set('address_line_1', '5 Long St');
        checkoutResponse = { status: 422, data: { message: 'x', reason: 'payment_method_blocked' } };
        await submit();

        assert.equal(radios[0].disabled, true);
        assert.equal(radios[0].checked, false);
        assert.equal(q('[data-sf-error-for="payment_method"]').textContent, T('ck_contact_shop'));
        assert.equal(T('ck_contact_shop'), 'Свържете се посредством методите на контакт за възможността да поръчате от сайта', 'the owner\'s sentence, word for word');
        assert.match(q('[data-sf-error-summary]').textContent, /Свържете се посредством/);
        assert.equal(placeOrder.disabled, true, 'Поръчване is locked');

        await typeInto('city', 'Plovdiv');
        await flush();
        assert.equal(placeOrder.disabled, true, 'a new quote does not unlock it');

        // Even if the refused method is somehow ticked again (a script, an extension), the lock holds: it is a flag, not only "nothing is chosen".
        radios[0].checked = true;
        radios[0].dispatch('change');
        assert.equal(placeOrder.disabled, true, 'the lock does not depend on what is ticked');

        await submit();
        assert.equal(checkoutPosts().length, 1, 'nothing more is ever sent');
    },

};

assert.ok(scenarios[scenario], `unknown scenario ${scenario}`);
await scenarios[scenario]();
console.log(`OK ${scenario}`);
