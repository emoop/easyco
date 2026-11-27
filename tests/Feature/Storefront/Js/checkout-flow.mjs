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
    get parentNode() { return this.parent; }
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
// COUR-2a: the list picker's scripted answers (method 6 offers a list in the `picker_*` scenarios; one scenario's lists fail)
const pickerFail = scenario === 'picker_falls_back_when_a_list_fails';
const pointsFail = scenario === 'picker_points_list_fails';
let checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
// ACC-3c: who is signed in, and the account's saved addresses (the API's own shape; `is_default` marks the default).
const signedIn = scenario.startsWith('acct_');
let sessionEnded = false;
const reloads = [];
const addr = (id, extra) => ({ id: String(id), account_id: '7', delivery_type: 'street_address', recipient_first_name: 'Иван', recipient_last_name: 'Иванов', phone: '+359888111111', country: 'BG', city: 'Sofia', postal_code: '1000', address_line_1: `Street ${id}`, address_line_2: null, carrier_code: null, pickup_point_reference: null, settlement: null, pickup_point_name: null, pickup_point_address: null, pickup_point_kind: null, region: null, is_default: false, ...extra });
const point = (id, carrier, kind, where) => addr(id, { delivery_type: 'pickup_point', city: null, postal_code: null, address_line_1: null, carrier_code: carrier, pickup_point_kind: kind, settlement: 'Sofia', pickup_point_address: where });
// a refusal that leaves the form usable (a field error), for scenarios that press the button more than once
const REFUSED = { status: 422, data: { message: 'x', errors: { customer_note: ['x'] } } };
const savedAddresses = [
    // COUR-2a.5: a street address saved before the postcode became required has none
    addr(1, { is_default: true, ...(scenario.includes('without_a_postcode') ? { postal_code: null } : {}) }),
    addr(2, { recipient_first_name: 'Мария', recipient_last_name: 'Иванова', phone: '+359888222222', city: 'Plovdiv', postal_code: '4000' }),
    addr(3), addr(4),
    point(11, 'Еконт', 'office', 'Econt Center 1'),
    point(12, 'Спиди', 'office', 'Speedy Office 2'),
    point(13, 'Еконт', 'locker', 'Econt Locker 3'),
].slice(0, scenario.includes('without_a_saved_point') ? 4 : 7);

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
    location: { href: 'http://shop.test/checkout', origin: 'http://shop.test', replace: (u) => replaced.push(u), reload: () => reloads.push(1) },
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
        { id: 6, name: 'Speedy office', courier: 'Спиди', delivery_type: 'office', destinations: { office: dest(preview, 6, 'office', 350) }, integration: scenario.startsWith('picker_') || scenario.includes('_resolve_') ? { carrier: 'speedy', map: false } : null },
        // COUR-2a.3: ONE method that serves address, office AND locker (scope ANY, no delivery type), with a list; only in the `picker_any_*` scenarios
        ...(scenario.startsWith('picker_any') || scenario.includes('_resolve_') ? [{ id: 7, name: 'Omni delivery', courier: 'Омни', delivery_type: null, destinations: { address: dest(preview, 7, 'address', 600), office: dest(preview, 7, 'office', 400), locker: dest(preview, 7, 'locker', 300) }, integration: { carrier: 'speedy', map: false } }] : []),
        { id: 2, name: 'Local courier', courier: null, delivery_type: null, destinations: { address: dest(preview, 2, 'address', 700) } },
    ].filter((m) => ! dropped.includes(m.id));
    const groups = [
        { courier: 'Еконт', ids: [1, 4, 5], from: 0, destinations: ['address', 'office', 'locker'] },
        { courier: 'Спиди', ids: [6], from: 350, destinations: ['office'] },
        ...(scenario.startsWith('picker_any') || scenario.includes('_resolve_') ? [{ courier: 'Омни', ids: [7], from: 300, destinations: ['address', 'office', 'locker'] }] : []),
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
    if (method_ === 'GET' && path.startsWith('/api/shipping/settlements?')) {
        let found = [
            { id: '77', name: 'Пловдив', region: 'Пловдив', postcode: '4000', is_split: false, points: 2 },
            { id: '78', name: 'Пловдив', region: 'Хасково', postcode: '6300', is_split: false, points: 1 },
        ];

        // COUR-2a.5: the resolve scenarios answer like the real search: by the start of the name or of the postcode; `one_match` has only the first place
        if (scenario.includes('_resolve_')) {
            const typed = decodeURIComponent((path.match(/[?&]q=([^&]*)/) || [, ''])[1]).trim().toLowerCase().replace(/^(гр\.|с\.)\s*/, '');

            found = found.filter((item) => (! scenario.includes('one_match') || item.id === '77') && (item.name.toLowerCase().startsWith(typed) || 'plovdiv'.startsWith(typed) || item.postcode.startsWith(typed)));
        }

        result = pickerFail ? { status: 500, data: null } : { status: 200, data: { data: found } };
    }
    if (method_ === 'GET' && path.startsWith('/api/shipping/pickup-points?')) {
        result = pointsFail ? { status: 500, data: null } : { status: 200, data: { data: [
            { id: 501, ref: 'S-1', kind: 'office', name: 'Офис Тракия', address: 'ул. Тракия 1', postcode: '4000', lat: null, lng: null },
            { id: 502, ref: 'S-2', kind: 'office', name: 'Офис Център', address: 'бул. Христо Ботев 2', postcode: '4000', lat: null, lng: null },
        ] } };
    }
    // ACC-3c: the account endpoints. A scenario named `acct_*` is a signed-in customer (until `sessionEnded`); every other scenario is a guest (401).
    if (method_ === 'GET' && path === '/api/account/me') result = signedIn && ! sessionEnded ? { status: 200, data: { id: '7', email: 'me@example.com' } } : { status: 401, data: { message: 'Unauthenticated.' } };
    if (method_ === 'GET' && path === '/api/addresses') result = signedIn && ! sessionEnded ? { status: 200, data: { data: savedAddresses } } : { status: 401, data: { message: 'Unauthenticated.' } };
    if (method_ === 'POST' && path === '/api/account/logout') result = { status: 204, data: null };

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
// COUR-2a.4: the one place field (the city input becomes a search) and the courier's list of offices
const placeSearchRequests = () => calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/shipping/settlements'));
const pointRequests = () => calls.filter((c) => c.method === 'GET' && c.path.startsWith('/api/shipping/pickup-points'));
const placeOptions = () => form.elements.city.parent.querySelectorAll('[role="option"]');
const pointRadios = () => q('[data-sf-picker]').querySelectorAll('input').filter((n) => n.attrs.type === 'radio');
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
        // COUR-2a: the free-text block still has ONE input; the list picker's own inputs are built by the script, inside its own host
        assert.equal(q('[data-sf-pickup-manual]').all().filter((n) => n.tag === 'input').length, 1, 'ONE pick-up input');
        assert.ok(q('[data-sf-picker]'), 'the host of the list picker is on the page');
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

    // COUR-2a.4: the owner's order. Courier and type first (from the preview quote), then ONE place field (a search over our settlements, by name or
    // postcode), then the courier's offices of that place. The list has no place field of its own; the order carries OUR point id and no typed text.
    async picker_list_flow() {
        choose(courierRows()[1].radio);   // Speedy: one kind (office), a list; chosen BEFORE any place is typed

        const host = q('[data-sf-picker]');
        const city = form.elements.city;

        assert.equal(host.hidden, false, 'the list area is shown for the chosen office');
        assert.equal(q('[data-sf-pickup-manual]').hidden, true, 'the free-text office field is not');
        assert.equal(form.elements.pickup_point_address.required, false);
        assert.equal(city.attrs.role, 'combobox', 'the place is a search');
        assert.equal(host.textContent.includes(T('ck_pick_choose_place')), true, 'the list asks for the place first');
        assert.equal(placeSearchRequests().length, 0);
        assert.equal(placeOrder.disabled, true, 'no place, no point yet');

        // typing quotes nothing (a half-typed name is no place); one character asks nothing
        const quotesBefore = quotePosts().length;
        city.value = 'п';
        city.dispatch('input');
        await flush();
        assert.equal(placeSearchRequests().length, 0);
        city.value = 'пло';
        city.dispatch('input');
        await flush();
        assert.equal(quotePosts().length, quotesBefore, 'no quote while typing the place');
        assert.match(placeSearchRequests()[0].path, /kind=office&carrier=speedy&q=/, 'only places where this courier has offices');

        const options = placeOptions();

        assert.equal(options.length, 2);
        assert.ok(options[0].textContent.includes(T('ck_pick_region').replace(':region', 'Пловдив')), 'the oblast is in every suggestion');
        assert.ok(options[0].textContent.includes('(4000)'), 'and the postcode');
        assert.ok(options[1].textContent.includes(T('ck_pick_region').replace(':region', 'Хасково')));

        // a postcode searches too
        city.value = '4000';
        city.dispatch('input');
        await flush();
        assert.match(placeSearchRequests().at(-1).path, /q=4000/);

        // keyboard: down, down, up, Enter chooses the first; Enter never submits the form
        const key = (name) => {
            const event = { type: 'keydown', key: name, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
            city.listeners.keydown.forEach((fn) => fn(event));

            return event;
        };
        key('ArrowDown');
        key('ArrowDown');
        key('ArrowUp');
        assert.equal(city.attrs['aria-activedescendant'], placeOptions()[0].attrs.id);
        assert.equal(key('Enter').defaultPrevented, true);
        await flush();

        assert.equal(city.value, 'Пловдив');
        assert.equal(form.elements.postal_code.value, '4000', 'the postcode is filled once');
        assert.equal(form.elements.region.value, 'Пловдив', 'and the region');
        assert.equal(quotePosts().at(-1).body.settlement, 'Пловдив', 'the real quote runs for the chosen place');
        assert.match(pointRequests().at(-1).path, /carrier=speedy&kind=office&settlement=77/);

        const radios = pointRadios();

        assert.equal(radios.length, 2);
        assert.equal(host.querySelectorAll('input').filter((n) => n.attrs.type === 'search' || n.attrs.role === 'combobox').length, 0, 'the list has no place field of its own');
        fillContact();
        assert.equal(placeOrder.disabled, true, 'a place is not a point');
        radios[1].checked = true;
        radios[1].dispatch('change');
        await flush();

        assert.equal(form.elements.pickup_point_id.value, '502');
        assert.equal(placeOrder.disabled, false);
        assert.ok(host.querySelector('[data-sf-picker-summary-text]').textContent.includes('Офис Център'));

        // the quote that follows the choice leaves everything as it is (same place, same method, same destination)
        await flush();
        assert.equal(form.elements.pickup_point_id.value, '502', 'a re-quote keeps the office');
        const requests = pointRequests().length;
        await flush();
        assert.equal(pointRequests().length, requests, 'no loop of requests');

        await submit();

        const body = lastCheckout().body;

        assert.equal(body.pickup_point_id, '502');
        assert.equal(body.pickup_point_kind, 'office');
        assert.equal(body.settlement, 'Пловдив');
        for (const absent of ['pickup_point_address', 'pickup_point_manual', 'pickup_point_name', 'pickup_point_reference']) {
            assert.equal(absent in body, false, `${absent} is not sent for a chosen point`);
        }
    },

    // ONE method that serves every destination (scope ANY, no delivery type): the kind is the destination the CUSTOMER chose. The place is kept when
    // the courier or the type changes; the office is dropped; the free-text and required rules follow the same path.
    async picker_any_method_follows_the_chosen_destination() {
        const omni = courierRows().find((r) => r.text.startsWith('Омни'));
        const host = q('[data-sf-picker]');
        const city = form.elements.city;

        choose(omni.radio);
        assert.equal(host.hidden, true, 'no destination chosen yet');
        chooseKind('7:address');
        assert.equal(host.hidden, true);
        assert.equal(city.attrs.role, 'combobox', 'a street address chooses its place from ALL settlements too');

        city.value = 'пло';
        city.dispatch('input');
        await flush();
        assert.match(placeSearchRequests().at(-1).path, /kind=address&q=/);
        assert.doesNotMatch(placeSearchRequests().at(-1).path, /carrier=/, 'every settlement, no courier');
        placeOptions()[0].dispatch('mousedown');
        await flush();
        assert.equal(form.elements.postal_code.value, '4000', 'the postcode is prefilled for the street address ...');
        form.elements.postal_code.value = '4001';
        assert.equal(form.elements.postal_code.value, '4001', '... and stays editable');
        assert.equal(form.elements.address_line_1.required, true);

        // address -> office: the same place, the list of that place
        chooseKind('7:office');
        await flush();
        assert.equal(host.hidden, false);
        assert.equal(city.value, 'Пловдив', 'the place is kept');
        assert.equal(form.elements.pickup_point_address.required, false);
        assert.match(pointRequests().at(-1).path, /kind=office&settlement=77/);
        pointRadios()[0].checked = true;
        pointRadios()[0].dispatch('change');
        assert.equal(form.elements.pickup_point_id.value, '501');

        // office -> locker: the office is dropped, the place is kept, the list is the locker list
        chooseKind('7:locker');
        await flush();
        assert.equal(form.elements.pickup_point_id.value, '', 'a point is for ONE destination');
        assert.equal(city.value, 'Пловдив');
        assert.match(pointRequests().at(-1).path, /kind=locker&settlement=77/);
        assert.equal(pointRadios().length, 2);

        // locker -> address -> office again
        chooseKind('7:address');
        assert.equal(host.hidden, true);
        assert.equal(q('[data-sf-pickup-fields]').hidden, true);
        assert.equal(form.elements.pickup_point_address.required, false);
        assert.equal(form.elements.address_line_1.required, true);
        chooseKind('7:office');
        await flush();
        assert.equal(host.hidden, false);
        assert.equal(form.elements.pickup_point_id.value, '');
        assert.equal(pointRadios().length, 2, 'the place is kept, so the list is back');

        // a new place drops the office
        pointRadios()[1].checked = true;
        pointRadios()[1].dispatch('change');
        assert.equal(form.elements.pickup_point_id.value, '502');
        city.value = 'Хас';
        city.dispatch('input');
        assert.equal(form.elements.pickup_point_id.value, '', 'changing the place drops the office');
    },

    // «Промени» drops the point and shows the list again; another courier drops it for good (the place stays).
    async picker_change_and_switching_drop_the_point() {
        choose(courierRows()[1].radio);

        const host = q('[data-sf-picker]');
        const city = form.elements.city;

        city.value = 'пло';
        city.dispatch('input');
        await flush();
        placeOptions()[0].dispatch('mousedown');
        await flush();
        pointRadios()[0].checked = true;
        pointRadios()[0].dispatch('change');
        assert.equal(form.elements.pickup_point_id.value, '501');

        host.querySelector('[data-sf-picker-change]').dispatch('click');
        assert.equal(form.elements.pickup_point_id.value, '', 'changing drops the point');
        assert.equal(pointRadios().length, 2, 'the list is shown again');

        pointRadios()[0].checked = true;
        pointRadios()[0].dispatch('change');
        assert.equal(form.elements.pickup_point_id.value, '501');

        choose(courierRows()[0].radio);
        chooseKind('4:office');
        assert.equal(host.hidden, true, 'a method without a list shows none');
        assert.equal(q('[data-sf-pickup-manual]').hidden, false);
        assert.equal(city.value, 'Пловдив', 'the place stays');

        choose(courierRows()[1].radio);
        await flush();
        assert.equal(host.hidden, false);
        assert.equal(form.elements.pickup_point_id.value, '', 'the old point is gone');
        assert.equal(pointRadios().length, 2, 'and the list of the kept place is back');
    },

    // The place list fails to load: the place is a plain text field again, the office is typed too, and the order says so.
    async picker_falls_back_when_a_list_fails() {
        choose(courierRows()[1].radio);

        const city = form.elements.city;

        fillContact();
        city.value = 'пло';
        city.dispatch('input');
        await flush();

        assert.equal(city.attrs.role, undefined, 'the place is a plain text field again');
        assert.equal(q('[data-sf-pickup-manual]').hidden, false, 'the free-text office field is back');
        assert.equal(form.elements.pickup_point_address.required, true);
        assert.equal(placeOrder.disabled, true);

        // typing quotes as it always did
        const before = quotePosts().length;
        await typeInto('city', 'Sofia');
        assert.equal(quotePosts().length > before, true);

        fillPoint('S-9');
        assert.equal(placeOrder.disabled, false);
        await submit();

        const body = lastCheckout().body;

        assert.equal(body.pickup_point_address, 'S-9 Main St');
        assert.equal(body.pickup_point_manual, true);
        assert.equal(body.settlement, 'Sofia');
        assert.equal('pickup_point_id' in body, false);
    },

    // «Не намирам населеното си място» keeps today's free-text place; the list cannot know it, so the office is typed.
    async picker_place_not_found_keeps_the_free_text_place() {
        choose(courierRows()[1].radio);

        const city = form.elements.city;
        const manualButton = city.parent.querySelectorAll('button').find((b) => b.textContent === T('ck_place_manual'));

        assert.equal(manualButton.hidden, false);
        manualButton.dispatch('click');
        await flush();

        assert.equal(city.attrs.role, undefined);
        assert.equal(q('[data-sf-pickup-manual]').hidden, false);
        await typeInto('city', 'Нейкое село');
        fillContact();
        fillPoint('S-3');
        await submit();

        const body = lastCheckout().body;

        assert.equal(body.settlement, 'Нейкое село');
        assert.equal(body.pickup_point_manual, true);
        assert.equal(body.pickup_point_address, 'S-3 Main St');

        // and back to the search
        const back = city.parent.querySelectorAll('button').find((b) => b.textContent === T('ck_place_back'));
        back.dispatch('click');
        assert.equal(city.attrs.role, 'combobox');
    },

    // The places load but the OFFICES of the chosen place do not: free text for the office only.
    async picker_points_list_fails() {
        choose(courierRows()[1].radio);

        const city = form.elements.city;

        city.value = 'пло';
        city.dispatch('input');
        await flush();
        placeOptions()[0].dispatch('mousedown');
        await flush();

        assert.equal(city.attrs.role, 'combobox', 'the place search still works');
        assert.equal(q('[data-sf-pickup-manual]').hidden, false);
        assert.equal(q('[data-sf-pickup-note]').textContent, T('ck_pick_failed'));
        fillContact();
        fillPoint('S-4');
        await submit();
        assert.equal(lastCheckout().body.pickup_point_manual, true);
        assert.equal(lastCheckout().body.settlement, 'Пловдив');
    },

    // The server says the chosen point is gone: the point is dropped, the list is shown again, nothing is lost.
    async picker_server_refusal_drops_the_point() {
        choose(courierRows()[1].radio);
        fillContact();

        const host = q('[data-sf-picker]');
        const city = form.elements.city;

        city.value = 'пло';
        city.dispatch('input');
        await flush();
        placeOptions()[0].dispatch('mousedown');
        await flush();
        pointRadios()[0].checked = true;
        pointRadios()[0].dispatch('change');

        checkoutResponse = { status: 422, data: { message: 'x', errors: { pickup_point_id: ['Gone'] } } };
        await submit();
        await flush();

        assert.equal(form.elements.pickup_point_id.value, '', 'the refused point is dropped');
        assert.equal(host.querySelector('[data-sf-error-for="pickup_point_id"]').hidden, false);
        assert.equal(host.querySelector('[data-sf-error-for="pickup_point_id"]').textContent, 'Gone');
        assert.equal(placeOrder.disabled, true, 'a new point is needed');
        assert.equal(pointRadios().length, 2, 'the list is shown again');
        assert.equal(city.value, 'Пловдив', 'the place is kept');
        assert.equal(form.elements.recipient_first_name.value, 'Ana', 'what was typed is kept');
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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

        assert.equal(input.attrs.placeholder, ' ', 'C1e: a one-space placeholder; the floating label carries the promo text');
        assert.equal(label.attrs.for, input.id, 'a real label stays, for screen readers');
        assert.equal(label.textContent, T('promo_label'));
        assert.match(label.className, /sf-promo-compact-label/, 'the label keeps its class (C1e: it is the visible floating label now)');
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode

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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
    // S3-V2 (fix 2): collapsed below 1024px the summary is a PEEK: hidden from assistive technology and everything IN it inert (the panel itself
    // stays tappable); open, or from 1024px, both are removed. Tapping the peek runs the SAME function as the bar, only while collapsed.
    async summary_peek_access() {
        const toggle = q('[data-sf-summary-toggle]');
        const panel = q('#sf-summary-panel');
        const inertOf = () => panel.children.map((c) => 'inert' in c.attrs);
        const resize = (width) => { globalThis.window.innerWidth = width; (windowListeners.resize || []).forEach((fn) => fn()); };

        assert.ok(panel.children.length >= 4, 'the panel has its title, lines, promo, error and totals');
        assert.equal(panel.hidden, false, 'a cart with lines: the peek is there');

        // A desktop width: the summary is the right column, always interactive.
        assert.equal('aria-hidden' in panel.attrs, false);
        assert.ok(inertOf().every((x) => x === false));

        resize(375);
        assert.equal(panel.getAttribute('aria-hidden'), 'true', 'collapsed on a phone: hidden from assistive technology');
        assert.ok(inertOf().every((x) => x === true), 'and nothing in it can take focus');
        assert.equal('inert' in panel.attrs, false, 'the panel itself is NOT inert: a browser would not deliver the tap on the peek');

        // The bar opens it: both come off.
        toggle.dispatch('click');
        assert.equal(panel.getAttribute('data-open'), 'true');
        assert.equal('aria-hidden' in panel.attrs, false, 'open: visible to assistive technology');
        assert.ok(inertOf().every((x) => x === false), 'open: everything is interactive again');

        // A tap on the OPEN panel does nothing extra (its buttons and fields have their own handlers).
        panel.dispatch('click');
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');
        assert.equal(q('[data-sf-summary-toggle-label]').textContent, T('ck_summary_hide'));

        toggle.dispatch('click');
        assert.equal(panel.getAttribute('data-open'), 'false');
        assert.equal(panel.getAttribute('aria-hidden'), 'true');
        assert.ok(inertOf().every((x) => x === true));

        // A tap on the PEEK is the same as the bar: the summary opens, the label and the aria state follow.
        panel.dispatch('click');
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');
        assert.equal(panel.getAttribute('data-open'), 'true');
        assert.equal(q('[data-sf-summary-toggle-label]').textContent, T('ck_summary_hide'));
        assert.ok(inertOf().every((x) => x === false));

        // Back to a desktop width while collapsed: nothing stays hidden.
        toggle.dispatch('click');
        assert.equal(panel.getAttribute('aria-hidden'), 'true');
        resize(1280);
        assert.equal('aria-hidden' in panel.attrs, false);
        assert.ok(inertOf().every((x) => x === false));
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode

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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode

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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode

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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
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


    // C1e (G4): the postal code sits next to the place. Shown while no kind is chosen and for an address; hidden for an office or a locker;
    // the quote keeps sending it with the place, whatever the kind; a pick-up order never carries it.
    async postal_code_follows_the_kind() {
        const postal = q('[data-sf-postal-field]');
        assert.equal(postal.hidden, false, 'shown before any choice is made');
        assert.equal(q('[data-sf-address-fields]').hidden, true, 'while the street block still waits for its kind');
        assert.equal(postal.querySelector('input').name, 'postal_code', 'the same field, the same name');
        assert.equal(postal.querySelector('[data-sf-error-for="postal_code"]') !== null, true, 'and its own error holder');

        await typeInto('city', 'Sofia');
        choose(courierRows()[0].radio);
        assert.equal(postal.hidden, false, 'a courier with several kinds, none chosen yet');
        chooseKind('1:address');
        assert.equal(postal.hidden, false, 'an address');
        await typeInto('postal_code', '1000');

        chooseKind('4:office');
        assert.equal(postal.hidden, true, 'hidden for an office');
        chooseKind('5:locker');
        assert.equal(postal.hidden, true, 'hidden for a locker');
        chooseKind('1:address');
        assert.equal(postal.hidden, false, 'and back again for an address');
        chooseKind('4:office');
        assert.equal(postal.hidden, true);

        // The quote is unchanged: the place and, when typed, the postal code, whatever the kind.
        await typeInto('city', 'Plovdiv');
        assert.deepEqual(quotePosts().at(-1).body, { delivery_type: 'all', country: 'BG', settlement: 'Plovdiv', postal_code: '1000' });

        // A pick-up order: the field is hidden, so its value is NOT part of the payload.
        assert.equal(postal.hidden, true);
        fillContact();
        fillPoint('E-9');
        await submit();
        const body = lastCheckout().body;
        assert.equal(body.delivery_type, 'pickup_point');
        assert.equal('postal_code' in body, false, 'a pick-up order never sends the postal code');
        assert.equal(body.settlement, 'Plovdiv');
    },

    // C1e (G4): for a street address the postal code is sent as before.
    async postal_code_is_sent_for_an_address() {
        await typeInto('city', 'Sofia');
        choose(courierRows()[2].radio);
        assert.equal(q('[data-sf-postal-field]').hidden, false);
        fillContact();
        set('address_line_1', '5 Long St');
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
        await typeInto('postal_code', '1000');
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.delivery_type, 'street_address');
        assert.equal(body.postal_code, '1000');
        assert.equal(body.city, 'Sofia');
    },

    // C1e (G1): the checkout's promo input carries the one-space placeholder the floating label needs; the cart page's box is untouched.
    async promo_input_has_the_floating_placeholder() {
        assert.equal(promoInput().attrs.placeholder, ' ');
        assert.equal(promoForm().querySelector('label').textContent, T('promo_label'), 'the visible floating label is the promo text');
    },


    // ------------------------------------------------------------------------------------------------------------------------------------ ACC-3c
    // The page is the same for everyone; who is signed in comes from GET /api/account/me after load. A scenario named `acct_*` is a signed-in customer.

    async guest_sees_the_login_hint_and_keeps_the_email_field() {
        checkoutResponse = REFUSED;
        assert.equal(q('[data-sf-login-hint]').hidden, false);
        assert.equal(q('[data-sf-login-link]').attrs.href, '/account/login?redirect=/checkout');
        assert.equal(q('[data-sf-order-as]').hidden, true);
        assert.equal(q('[data-sf-email-field]').hidden, false);
        assert.equal(form.elements.email.required, true);
        assert.equal(q('[data-sf-saved]').hidden, true);
        assert.equal(calls.some((c) => c.path === '/api/addresses'), false, 'a guest never asks for addresses');
        assert.equal(q('[data-sf-recipient-field]').hidden, true);

        // the recipient field opens and closes; closing clears it; open and filled it travels
        q('[data-sf-recipient-toggle]').dispatch('click');
        assert.equal(q('[data-sf-recipient-field]').hidden, false);
        assert.equal(q('[data-sf-recipient-toggle]').attrs['aria-expanded'], 'true');
        set('recipient_email', 'friend@example.com');
        await typeInto('city', 'Sofia');
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
        choose(courierRows()[2].radio);
        fillContact();
        await submit();
        assert.equal(lastCheckout().body.email, 'a@example.com');
        assert.equal(lastCheckout().body.recipient_email, 'friend@example.com');

        q('[data-sf-recipient-toggle]').dispatch('click');
        assert.equal(q('[data-sf-recipient-field]').hidden, true);
        assert.equal(form.elements.recipient_email.value, '', 'hiding it again clears it');
        await submit();
        assert.equal(checkoutPosts().length, 2);
        assert.equal('recipient_email' in lastCheckout().body, false);
    },

    async acct_default_address_is_preselected_and_goes_out_as_its_id() {
        assert.equal(q('[data-sf-order-as]').hidden, false);
        assert.match(q('[data-sf-order-as]').textContent, /me@example\.com/);
        assert.equal(q('[data-sf-login-hint]').hidden, true);
        assert.equal(q('[data-sf-email-field]').hidden, true);
        assert.equal(form.elements.email.required, false, 'a hidden empty required field would block the form');
        assert.equal(q('label[for="sf-email"]').querySelector('[data-sf-marker]'), null, 'and carries no asterisk');

        const rows = rowsOf(q('[data-sf-saved-list]'));
        assert.equal(q('[data-sf-saved]').hidden, false);
        assert.equal(rows.length, 4, 'the first three of four streets, and «Нов адрес»');
        assert.match(rows[0].text, /^Иван Иванов · Street 1 · Sofia 1000/);
        assert.equal(rows[0].radio.checked, true, 'the default is preselected');
        assert.equal(q('[data-sf-saved-more]').hidden, false);
        assert.equal(q('[data-sf-saved-more]').textContent, q('[data-sf-saved]').attrs['data-text-more'].replace(':count', '1'));
        assert.equal(form.attrs['data-sf-saved-mode'], 'saved', 'the fields are hidden');
        assert.equal(form.elements.recipient_first_name.value, 'Иван');
        assert.equal(form.elements.phone.value, '+359888111111');
        assert.equal(form.elements.city.value, 'Sofia');
        assert.equal(quotePosts().at(-1).body.settlement, 'Sofia', 'the default place is what was quoted');

        choose(courierRows()[2].radio);
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.address_id, '1');
        for (const gone of ['email', 'delivery_type', 'country', 'city', 'postal_code', 'address_line_1', 'address_line_2', 'settlement', 'carrier_code', 'pickup_point_kind']) {
            assert.equal(gone in body, false, `${gone} is not sent with address_id`);
        }
        assert.equal(body.recipient_first_name, 'Иван');
        assert.equal(body.phone, '+359888111111');
        assert.ok(body.shipping_method_id && body.quote_handle, 'the delivery choice travels as before');
    },

    async acct_more_addresses_reveals_the_rest() {
        assert.equal(rowsOf(q('[data-sf-saved-list]')).length, 4);
        q('[data-sf-saved-more]').dispatch('click');
        assert.equal(rowsOf(q('[data-sf-saved-list]')).length, 5);
        assert.equal(q('[data-sf-saved-more]').hidden, true);
    },

    async acct_switching_to_the_second_address_fills_and_requotes() {
        const quotes = quotePosts().length;
        rowsOf(q('[data-sf-saved-list]'))[1].radio.dispatch('click');
        await flush();

        assert.equal(form.elements.recipient_first_name.value, 'Мария');
        assert.equal(form.elements.city.value, 'Plovdiv');
        assert.ok(quotePosts().length > quotes);
        assert.equal(quotePosts().at(-1).body.settlement, 'Plovdiv');

        choose(courierRows()[2].radio);
        await submit();
        assert.equal(lastCheckout().body.address_id, '2');
    },

    async acct_change_sends_the_full_fields_and_never_the_id() {
        checkoutResponse = REFUSED;
        q('[data-sf-saved-list]').querySelector('button').dispatch('click');
        assert.equal('data-sf-saved-mode' in form.attrs, false, '«Промени» shows the fields');
        assert.equal(form.elements.address_line_1.value, 'Street 1', 'copied into the editable fields');
        set('address_line_1', 'New 9');
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
        choose(courierRows()[2].radio);
        await submit();

        let body = lastCheckout().body;
        assert.equal('address_id' in body, false);
        assert.equal(body.delivery_type, 'street_address');
        assert.equal(body.address_line_1, 'New 9');
        assert.equal(body.city, 'Sofia');
        assert.equal('email' in body, false, 'the account email is the server\'s');

        // «Промени» without changing anything is still the full fields: the server's dedupe reuses the record
        await submit();
        assert.equal(checkoutPosts().length, 2);
        assert.equal('address_id' in lastCheckout().body, false);
        assert.equal(lastCheckout().body.address_line_1, 'New 9');
    },

    async acct_editing_the_name_or_phone_of_a_chosen_address_sends_the_full_fields() {
        choose(courierRows()[2].radio);
        set('phone', '+359888999999');
        await submit();

        const body = lastCheckout().body;
        assert.equal('address_id' in body, false, 'the phone is part of the address: changed, so a new record may be needed');
        assert.equal(body.phone, '+359888999999');
        assert.equal(body.address_line_1, 'Street 1');
        assert.equal(body.delivery_type, 'street_address');
    },

    async acct_new_address_for_another_person_with_a_recipient_email() {
        rowsOf(q('[data-sf-saved-list]')).at(-1).radio.dispatch('click');
        await flush();

        assert.equal('data-sf-saved-mode' in form.attrs, false);
        assert.equal(form.elements.recipient_first_name.value, 'Иван', 'the person comes from the default address');
        assert.equal(form.elements.recipient_last_name.value, 'Иванов');
        assert.equal(form.elements.phone.value, '+359888111111');
        assert.equal(form.elements.city.value, '', 'the address itself starts empty');
        assert.equal(form.elements.address_line_1.value, '');

        set('recipient_first_name', 'Мама');
        set('recipient_last_name', 'Петрова');
        q('[data-sf-recipient-toggle]').dispatch('click');
        set('recipient_email', 'Mum@Example.com');
        await typeInto('city', 'Varna');
        choose(courierRows()[2].radio);
        set('address_line_1', 'Sea 1');
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
        await submit();

        const body = lastCheckout().body;
        assert.equal('address_id' in body, false);
        assert.equal(body.recipient_first_name, 'Мама');
        assert.equal(body.city, 'Varna');
        assert.equal(body.address_line_1, 'Sea 1');
        assert.equal(body.recipient_email, 'Mum@Example.com');
        assert.equal('email' in body, false);
    },

    // Only the saved points of the SAME courier and kind; switching courier and kind any number of times keeps working.
    async acct_office_offers_only_saved_points_of_the_same_courier_and_kind() {
        const list = () => rowsOf(q('[data-sf-saved-list]')).map((r) => r.text.replace(/ Промени$/, ''));
        const checked = () => rowsOf(q('[data-sf-saved-list]')).filter((r) => r.radio.checked).map((r) => r.text);

        choose(courierRows()[0].radio);
        chooseKind('4:office');
        assert.equal(list().length, 2, 'one Econt office point and «Нов адрес»');
        assert.match(list()[0], /Econt Center 1/);
        assert.equal(checked().length, 1);
        assert.equal(form.elements.pickup_point_address.value, 'Econt Center 1');
        assert.equal(form.attrs['data-sf-saved-mode'], 'saved');

        choose(courierRows()[1].radio);
        assert.equal(list().length, 2);
        assert.match(list()[0], /Speedy Office 2/, 'the other courier: its own point');
        assert.equal(form.elements.pickup_point_address.value, 'Speedy Office 2');

        choose(courierRows()[0].radio);
        assert.match(list()[0], /Econt Center 1/);
        chooseKind('5:locker');
        assert.match(list()[0], /Econt Locker 3/, 'another kind of the same courier');
        assert.equal(list().length, 2);

        chooseKind('1:address');
        assert.equal(list().length, 4, 'back to the street addresses');
        assert.match(list()[0], /Street 1/);
        assert.equal(form.elements.address_line_1.value, 'Street 1');
        assert.equal(form.elements.pickup_point_address.value, '');

        chooseKind('4:office');
        assert.match(list()[0], /Econt Center 1/);

        await submit();
        const body = lastCheckout().body;
        assert.equal(body.address_id, '11', 'the saved point goes out as its id');
        assert.equal('carrier_code' in body, false);
        assert.equal('pickup_point_kind' in body, false);
        assert.equal(body.shipping_method_id, '4');
    },

    async acct_office_without_a_saved_point_is_the_usual_single_field() {
        choose(courierRows()[0].radio);
        chooseKind('4:office');

        assert.equal(q('[data-sf-saved]').hidden, true, 'nothing saved fits this courier and kind');
        assert.equal('data-sf-saved-mode' in form.attrs, false);
        assert.equal(q('[data-sf-pickup-fields]').hidden, false);

        fillPoint('E-1');
        await submit();

        const body = lastCheckout().body;
        assert.equal('address_id' in body, false);
        assert.equal(body.delivery_type, 'pickup_point');
        assert.equal(body.carrier_code, 'Еконт');
        assert.equal(body.pickup_point_kind, 'office');
        assert.equal(body.pickup_point_address, 'E-1 Main St');
        assert.equal('email' in body, false);

        // and switching back to a kind that has saved rows brings them back
        chooseKind('1:address');
        assert.equal(q('[data-sf-saved]').hidden, false);
    },

    async acct_an_ended_session_keeps_the_form_and_asks_for_the_email() {
        choose(courierRows()[2].radio);
        set('customer_note', 'Please call');
        form.elements.customer_note.dispatch('input');
        sessionEnded = true;
        checkoutResponse = { status: 422, data: { message: 'x', errors: { email: ['The email field is required.'] } } };
        await submit();

        assert.equal('email' in lastCheckout().body, false, 'it was sent as a signed-in order');
        assert.equal(q('[data-sf-email-field]').hidden, false, 'the email field is back');
        assert.equal(form.elements.email.required, true);
        assert.equal(q('[data-sf-order-as]').hidden, true);
        assert.equal(q('[data-sf-login-hint]').hidden, false);
        assert.equal(q('[data-sf-saved]').hidden, true);
        assert.match(q('[data-sf-error-summary]').textContent, new RegExp(q('[data-sf-saved]').attrs['data-text-expired']));
        assert.equal(form.elements.customer_note.value, 'Please call', 'everything else the customer entered is kept');
        assert.equal(form.elements.recipient_first_name.value, 'Иван');
        assert.equal(form.elements.city.value, 'Sofia');

        // as a guest the order goes out with the typed email and the full address
        set('email', 'guest@example.com');
        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        await submit();

        const body = lastCheckout().body;
        assert.equal(body.email, 'guest@example.com');
        assert.equal('address_id' in body, false);
        assert.equal(body.city, 'Sofia');
        assert.equal(body.address_line_1, 'Street 1');
    },

    async acct_a_401_or_419_is_the_same_expired_session() {
        choose(courierRows()[2].radio);
        sessionEnded = true;
        checkoutResponse = { status: 401, data: { message: 'Unauthenticated.' } };
        await submit();

        assert.equal(q('[data-sf-email-field]').hidden, false);
        assert.match(q('[data-sf-error-summary]').textContent, new RegExp(q('[data-sf-saved]').attrs['data-text-expired']));
    },

    // A 422 on a field that «saved» mode hides: the fields show (it is «Промени» now) and the error stands beside its field; any other error leaves the saved choice alone.
    async acct_a_422_on_a_hidden_field_switches_to_edit_and_shows_the_error() {
        choose(courierRows()[2].radio);

        checkoutResponse = { status: 422, data: { message: 'x', errors: { customer_note: ['Too long'] } } };
        await submit();
        assert.equal(form.attrs['data-sf-saved-mode'], 'saved', 'an error on a visible field keeps the saved choice');

        set('phone', '+359888999999');
        checkoutResponse = { status: 422, data: { message: 'x', errors: { city: ['The city is not valid.'] } } };
        await submit();

        assert.equal('data-sf-saved-mode' in form.attrs, false, 'the hidden fields are shown');
        assert.equal(form.elements.city.value, 'Sofia', 'with what they held');
        const holder = q('[data-sf-error-for="city"]');
        assert.equal(holder.hidden, false);
        assert.equal(holder.textContent, 'The city is not valid.');
        assert.equal(rowsOf(q('[data-sf-saved-list]'))[0].text.includes(q('[data-sf-saved]').attrs['data-text-change']), false, 'no «Промени» button: it is already open');

        // the street field of a saved street address, and the pick-up field of a saved point, are in the same set
        set('address_line_1', 'Fixed 5');
        set('postal_code', '1000'); // COUR-2a.5: a delivery to an address needs its postcode
        checkoutResponse = { status: 201, data: { confirmation_url: 'http://shop.test/order/received/5?expires=1&signature=abc' } };
        await submit();
        assert.equal('address_id' in lastCheckout().body, false);
        assert.equal(lastCheckout().body.address_line_1, 'Fixed 5');
    },

    // Nothing saved fits the courier and kind for a moment (the usual field is shown): when a choice that fits comes back and the customer typed nothing,
    // the saved row is taken again, its fields filled; once they typed, it is theirs and nothing is overwritten.
    async acct_a_kind_without_saved_points_does_not_lose_the_saved_row_for_good_without_a_saved_point() {
        choose(courierRows()[0].radio);
        chooseKind('4:office');
        assert.equal(q('[data-sf-saved]').hidden, true);
        assert.equal('data-sf-saved-mode' in form.attrs, false);

        chooseKind('1:address');
        assert.equal(form.attrs['data-sf-saved-mode'], 'saved', 'the saved street is chosen again');
        assert.equal(form.elements.address_line_1.value, 'Street 1');
        assert.equal(rowsOf(q('[data-sf-saved-list]'))[0].radio.checked, true);

        // typed: theirs
        chooseKind('4:office');
        fillPoint('E-7');
        chooseKind('1:address');
        assert.equal('data-sf-saved-mode' in form.attrs, false, 'what the customer typed is not replaced behind their back');
    },

    // ----------------------------------------------------------------------------------------------------------------------------------- COUR-2a.5
    // A place that is ALREADY in the field (a saved address, autofill, a restored page) is resolved ONCE through the same settlements search, for every
    // trigger alike: the destination changing, a `change` of the field, the address book, `pageshow`. One confident match is chosen as if the customer had
    // chosen it; several or none are never guessed: the list opens and a hint says what to do.

    // postcode + name decide between two places with the same name; the customer's own text is kept; no loop of requests
    async picker_resolve_a_prefilled_place_by_postcode_and_name() {
        await flush();
        const city = form.elements.city;

        set('city', 'Пловдив');
        set('postal_code', '6300');   // autofill / a restored page set values without a single event
        choose(courierRows()[1].radio);
        await flush();

        assert.equal(placeSearchRequests().length, 1, 'ONE resolve');
        assert.match(placeSearchRequests()[0].path, /kind=office&carrier=speedy&q=%D0%9F/, 'the same endpoint the field uses, for the text that is there');
        assert.equal(city.attrs['data-settlement-id'], '78', 'the postcode told the two places apart');
        assert.equal(city.value, 'Пловдив', 'the text stays');
        assert.equal(form.elements.postal_code.value, '6300', 'and the postcode');
        assert.match(pointRequests().at(-1).path, /carrier=speedy&kind=office&settlement=78/);
        assert.equal(pointRadios().length, 2, 'the offices show with no typing');
        assert.equal(quotePosts().at(-1).body.settlement, 'Пловдив', 'the real quote runs once for the place');
        assert.ok(city.parent.querySelector('[role="status"]').textContent.includes('6300'), 'the postcode is shown with the place');

        const requests = [placeSearchRequests().length, pointRequests().length, quotePosts().length];

        await flush();
        await flush();
        assert.deepEqual([placeSearchRequests().length, pointRequests().length, quotePosts().length], requests, 'no loop');

        // a place that is already resolved is never resolved again: away and back
        choose(courierRows()[0].radio);
        choose(courierRows()[1].radio);
        await flush();
        assert.equal(placeSearchRequests().length, 1);
        assert.equal(city.attrs['data-settlement-id'], '78');
    },

    // a single place with that name is confident even without a postcode, and the empty postcode and region are filled from it (never overwritten)
    async picker_resolve_one_match_fills_an_empty_postcode() {
        await flush();
        set('city', 'гр. Пловдив');
        choose(courierRows()[1].radio);
        await flush();

        assert.equal(form.elements.city.attrs['data-settlement-id'], '77');
        assert.equal(form.elements.city.value, 'гр. Пловдив', 'the customer\'s own spelling stays');
        assert.equal(form.elements.postal_code.value, '4000', 'an empty postcode is filled from the place');
        assert.equal(form.elements.region.value, 'Пловдив');
        assert.equal(pointRadios().length, 2);
    },

    // two places, no postcode and no region: nothing is guessed. The list opens for the typed text and the hint stands under the field AND in the office area.
    async picker_resolve_an_ambiguous_place_is_not_guessed() {
        await flush();
        const city = form.elements.city;

        set('city', 'Пловдив');
        choose(courierRows()[1].radio);
        await flush();

        assert.equal(placeSearchRequests().length, 1);
        assert.equal(city.attrs['data-settlement-id'], undefined, 'no choice was made for the customer');
        assert.equal(placeOptions().length, 2, 'the suggestion list is open');
        assert.ok(city.parent.querySelector('[role="status"]').textContent.includes(T('ck_pick_choose_from_list')));
        assert.ok(q('[data-sf-picker]').textContent.includes(T('ck_pick_choose_from_list')), 'the office area says the same, not an empty box');
        assert.equal(pointRequests().length, 0);
        assert.equal(placeOrder.disabled, true);

        // the choice is the customer's and works as ever
        placeOptions()[1].dispatch('mousedown');
        await flush();
        assert.equal(city.attrs['data-settlement-id'], '78');
        assert.match(pointRequests().at(-1).path, /settlement=78/);
        assert.equal(pointRadios().length, 2);
        assert.equal(q('[data-sf-picker]').textContent.includes(T('ck_pick_choose_from_list')), false, 'the hint is gone');
    },

    // with no postcode the REGION decides
    async picker_resolve_the_region_decides_when_there_is_no_postcode() {
        await flush();
        set('city', 'Пловдив');
        set('region', 'обл. Хасково');
        choose(courierRows()[1].radio);
        await flush();

        assert.equal(form.elements.city.attrs['data-settlement-id'], '78');
        assert.equal(form.elements.postal_code.value, '6300');
    },

    // a place the search does not know (or spells differently): the hint, the typed text stays, the list is the way on
    async picker_resolve_a_place_that_is_not_found_shows_the_hint() {
        await flush();
        const city = form.elements.city;

        set('city', 'Нищо');
        choose(courierRows()[1].radio);
        await flush();

        assert.equal(city.attrs['data-settlement-id'], undefined);
        assert.equal(city.value, 'Нищо');
        assert.ok(city.parent.querySelector('[role="status"]').textContent.includes(T('ck_pick_no_match')));
        assert.ok(q('[data-sf-picker]').textContent.includes(T('ck_pick_choose_from_list')));

        // the same text is not asked again by the same trigger; typing again is a new place
        const asked = placeSearchRequests().length;

        choose(courierRows()[0].radio);
        choose(courierRows()[1].radio);
        await flush();
        assert.equal(placeSearchRequests().length, asked, 'tried once for this place and method');
    },

    // `change` (autofill, a text left behind), `pageshow` (the page shown again) and an edit all run the SAME rule
    async picker_resolve_follows_change_pageshow_and_edits() {
        await flush();
        const city = form.elements.city;

        choose(courierRows()[1].radio);
        await flush();
        assert.equal(placeSearchRequests().length, 0, 'an empty place is nothing to resolve');
        assert.ok(q('[data-sf-picker]').textContent.includes(T('ck_pick_choose_place')), 'the office area asks for the place, as before');

        set('city', 'Пловдив');
        set('postal_code', '4000');
        city.dispatch('change');
        await flush();
        assert.equal(city.attrs['data-settlement-id'], '77');
        assert.equal(placeSearchRequests().length, 1);

        // pageshow with the same text changes nothing
        windowListeners.pageshow.forEach((fn) => fn({ persisted: true }));
        await flush();
        assert.equal(placeSearchRequests().length, 1);
        assert.equal(city.attrs['data-settlement-id'], '77');

        // the text is changed behind the page's back (a restored form): the old place and its offices go, the new text is resolved
        set('city', 'Нищо');
        windowListeners.pageshow.forEach((fn) => fn({ persisted: true }));
        await flush();
        assert.equal(city.attrs['data-settlement-id'], undefined, 'the old place is gone');
        assert.equal(pointRadios().length, 0, 'and no stale office list under the changed place');
        assert.equal(placeSearchRequests().length, 2);

        // typing over a resolved place drops it at once
        set('city', 'Пловдив');
        city.dispatch('change');
        await flush();
        assert.equal(city.attrs['data-settlement-id'], '77');
        assert.equal(pointRadios().length, 2);
        city.value = 'Пловдивx';
        city.dispatch('input');
        assert.equal(city.attrs['data-settlement-id'], undefined);
        assert.equal(pointRadios().length, 0, 'the offices of the old place are cleared at once');
        assert.equal(form.elements.pickup_point_id.value, '');
    },

    // the order can be placed with the auto-resolved office: OUR point id and nothing typed
    async picker_resolve_an_office_of_a_resolved_place_can_be_ordered() {
        await flush();
        set('city', 'Пловдив');
        set('postal_code', '4000');
        choose(courierRows()[1].radio);
        await flush();
        fillContact();
        pointRadios()[0].checked = true;
        pointRadios()[0].dispatch('change');
        await flush();
        assert.equal(placeOrder.disabled, false);
        await submit();

        const body = lastCheckout().body;

        assert.equal(body.pickup_point_id, '501');
        assert.equal(body.settlement, 'Пловдив');
        assert.equal('postal_code' in body, false, 'a pick-up order carries no postcode: it comes from the place');
        assert.equal(body.delivery_type, 'pickup_point');
    },

    // an address delivery needs its postcode: required marker, the error under the field only after a press, and an office takes none
    async postcode_is_required_for_an_address_only() {
        await flush();
        choose(courierRows()[0].radio);
        chooseKind('1:address');

        assert.equal(markerOf('postal_code'), true, 'required for an address');
        assert.equal(form.elements.postal_code.attrs['aria-required'], 'true');
        assert.equal(form.elements.postal_code.required, true);
        assert.equal(form.elements.postal_code.attrs['aria-invalid'], undefined, 'no error on load');
        assert.equal(q('[data-sf-error-for="postal_code"]').hidden, true);

        fillContact();
        await typeInto('city', 'Sofia');
        set('address_line_1', '5 Long St');
        await submit();
        assert.equal(checkoutPosts().length, 0, 'nothing is sent without the postcode');
        assert.equal(q('[data-sf-error-for="postal_code"]').hidden, false);
        assert.equal(q('[data-sf-error-for="postal_code"]').textContent, T('ck_postal_required'));
        assert.equal(form.elements.postal_code.attrs['aria-invalid'], 'true');

        set('postal_code', '1000');
        await submit();
        assert.equal(checkoutPosts().length, 1);
        assert.equal(lastCheckout().body.postal_code, '1000');

        // an office or a locker is not asked for it
        chooseKind('4:office');
        assert.equal(markerOf('postal_code'), false);
        assert.equal(form.elements.postal_code.required, false);
    },

    // the postcode the server refuses is shown under ITS field
    async postcode_a_server_error_stands_under_the_field() {
        await flush();
        choose(courierRows()[0].radio);
        chooseKind('1:address');
        fillContact();
        await typeInto('city', 'Sofia');
        set('postal_code', '12');
        set('address_line_1', '5 Long St');
        checkoutResponse = { status: 422, data: { message: 'x', errors: { postal_code: ['The postal code must be 4 digits.'] } } };
        await submit();
        assert.equal(q('[data-sf-error-for="postal_code"]').textContent, 'The postal code must be 4 digits.');
        assert.equal(q('[data-sf-error-for="postal_code"]').hidden, false);
    },

    // ---- signed in

    // the address book: the picker follows the address that is chosen NOW; the old place and its offices are gone
    async acct_resolve_the_address_book_without_a_saved_point_follows_the_chosen_address() {
        const omni = courierRows().find((r) => r.text.startsWith('Омни'));
        const city = form.elements.city;

        choose(omni.radio);
        chooseKind('7:address');
        assert.equal(form.attrs['data-sf-saved-mode'], 'saved');

        const second = rowsOf(q('[data-sf-saved-list]'))[1];   // Plovdiv 4000

        second.radio.dispatch('click');
        await flush();
        assert.equal(city.value, 'Plovdiv');

        // address -> office: the saved street rows do not fit; the place of the address that is chosen NOW is resolved (Latin spelling, postcode decides)
        chooseKind('7:office');
        await flush();
        assert.equal(city.attrs['data-settlement-id'], '77');
        assert.equal(city.value, 'Plovdiv', 'what the address held stays');
        assert.equal(form.elements.postal_code.value, '4000');
        assert.match(pointRequests().at(-1).path, /settlement=77/);
        assert.equal(pointRadios().length, 2);

        // back to the street rows: the default address returns; the place of the OFFICE list is gone with it
        chooseKind('7:address');
        assert.equal(city.value, 'Sofia');
        assert.equal(city.attrs['data-settlement-id'], undefined);

        chooseKind('7:locker');
        await flush();
        assert.equal(pointRadios().length, 0, 'no stale offices under another place');
        assert.equal(city.attrs['data-settlement-id'], undefined, 'Sofia is not a place of this list: the hint, not a guess');
        assert.ok(q('[data-sf-picker]').textContent.includes(T('ck_pick_choose_from_list')));
    },

    // a saved OFFICE is the customer's choice already: it is not resolved, the place stays on the page; «Промени» hands over to the list
    async acct_resolve_a_saved_office_keeps_the_place_on_the_page_until_it_is_changed() {
        const city = form.elements.city;

        choose(courierRows()[1].radio);   // Speedy office: the saved Speedy office row is chosen
        await flush();

        assert.equal(form.attrs['data-sf-saved-mode'], 'saved');
        assert.equal(form.attrs['data-sf-saved-kind'], 'pickup', 'the stylesheet keeps the place visible for a saved office');
        assert.equal(placeSearchRequests().length, 0, 'a saved office is not resolved');
        assert.equal(city.value, 'Sofia');

        const change = q('[data-sf-saved-list]').querySelectorAll('button')[0];

        change.dispatch('click');
        await flush();
        assert.equal('data-sf-saved-mode' in form.attrs, false);
        assert.equal('data-sf-saved-kind' in form.attrs, false);
        assert.equal(placeSearchRequests().length, 1, '«Промени» runs the same resolve for the place the row filled in');
        assert.ok(q('[data-sf-picker]').textContent.includes(T('ck_pick_choose_from_list')), 'Sofia is not a place of this list: the hint');
    },

    // typing over the place of a saved office leaves that office (the same step as «Промени»)
    async acct_resolve_typing_over_the_place_of_a_saved_office_leaves_it() {
        const city = form.elements.city;

        choose(courierRows()[1].radio);
        await flush();
        assert.equal(form.attrs['data-sf-saved-mode'], 'saved');

        city.value = 'Пло';
        city.dispatch('input');
        await flush();
        assert.equal('data-sf-saved-mode' in form.attrs, false, 'the fields are shown: the customer is editing');
        assert.equal(q('[data-sf-pickup-manual]').hidden, true, 'and the list picker, not the free-text office field, is what they fill in');
    },

    // a saved street address without a postcode is opened for editing (the empty required field must be seen) and never goes out as its id
    async acct_resolve_a_saved_street_without_a_postcode_is_opened_and_asks_for_it_without_a_postcode() {
        choose(courierRows()[0].radio);
        chooseKind('1:address');

        assert.equal('data-sf-saved-mode' in form.attrs, false, 'not collapsed: the postcode field is on the page');
        assert.equal(form.elements.postal_code.value, '');
        assert.equal(markerOf('postal_code'), true);
        assert.equal(form.elements.postal_code.attrs['aria-invalid'], undefined, 'no red field on load');

        await submit();
        assert.equal(checkoutPosts().length, 0);
        assert.equal(q('[data-sf-error-for="postal_code"]').textContent, T('ck_postal_required'));

        set('postal_code', '2000');
        await submit();
        assert.equal('address_id' in lastCheckout().body, false, 'the full fields go, not the id of an incomplete address');
        assert.equal(lastCheckout().body.postal_code, '2000');
    },

    async acct_logout_posts_and_reloads_the_page() {
        q('[data-sf-logout]').dispatch('click');
        await flush();

        assert.equal(calls.some((c) => c.method === 'POST' && c.path === '/api/account/logout'), true);
        assert.equal(reloads.length, 1);
    },

};

assert.ok(scenarios[scenario], `unknown scenario ${scenario}`);
await scenarios[scenario]();
console.log(`OK ${scenario}`);
