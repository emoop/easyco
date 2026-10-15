// Behavioural check of the storefront CART PAGE script (resources/js/storefront-cart.js, stage S3-V) without a browser and without a
// new dependency: the REAL rendered /cart page is parsed into a tiny fake DOM and the REAL script runs against it with a scripted fetch.
//
//   node cart-flow.mjs <rendered-cart.html> <scenario>
//
// Exit code 0 and the line "OK <scenario>" when every assertion holds; the stack of the first failed assertion otherwise.
// Run by tests/Feature/Storefront/StorefrontCartFlowTest.php (skipped when node is not installed).
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

// ------------------------------------------------------------------------------------------------ the environment (the cart page)

const [, , htmlPath, scenario] = process.argv;
const dom = parse(readFileSync(htmlPath, 'utf8'));
const calls = [];
let cookie = 'easyco_has_cart=1; XSRF-TOKEN=tok';
let cartReply = null;       // a scripted answer of GET /api/cart: {status, data}
let patchDelay = 0;
let lines = [];
let promo = null;

globalThis.document = {
    get cookie() { return cookie; },
    getElementById: (id) => dom.querySelector(`#${id}`),
    querySelector: (s) => dom.querySelector(s),
    querySelectorAll: (s) => dom.querySelectorAll(s),
    createElement: (tag) => new FakeNode(tag),
};
globalThis.window = { location: { href: 'http://shop.test/cart', origin: 'http://shop.test' } };

const money = (minor) => ({ minor, currency: 'EUR' });
const fmt = (minor) => `${(minor / 100).toFixed(2)} €`;
const line = (id, quantity, extra = {}) => ({
    variation_id: id, sku: `S${id}`, product_name: `Dress ${id}`, product_url: `/product/dress-${id}`, quantity, attributes: [{ value: 'Red' }, { value: 'M' }],
    unit_price: money(1000), line_total: money(1000 * quantity), price_available: true, image: null, ...extra,
});
let hint = null;
const currentCart = () => {
    const subtotal = lines.reduce((sum, l) => sum + l.line_total.minor, 0);

    return {
        cart_id: 11, lines, subtotal: money(subtotal), total: money(subtotal - (promo ? 200 : 0)),
        promotion: promo === null ? null : { code: promo, valid: true, discount_amount: money(200) },
        free_shipping_hint: hint,
    };
};

globalThis.fetch = async (path, init = {}) => {
    const method_ = init.method || 'GET';
    const body = init.body ? JSON.parse(init.body) : undefined;
    calls.push({ method: method_, path, body });
    let result = { status: 404, data: null };

    if (method_ === 'GET' && path === '/api/cart') result = cartReply ?? { status: 200, data: currentCart() };

    const patch = /^\/api\/cart\/lines\/(\d+)$/.exec(path);

    if (method_ === 'PATCH' && patch) {
        if (patchDelay > 0) await new Promise((resolve) => setTimeout(resolve, patchDelay));

        const target = lines.find((l) => String(l.variation_id) === patch[1]);
        target.quantity = body.quantity;
        target.line_total = money(1000 * body.quantity);
        result = { status: 200, data: currentCart() };
    }

    return { status: result.status, json: async () => result.data };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 40));
const q = (s) => dom.querySelector(s);
const content = () => q('[data-sf-cart-content]');
const i18n = q('#sf-i18n');
const T = (key) => i18n.getAttribute(`data-m-${key.replace(/_/g, '-')}`);
const patches = () => calls.filter((c) => c.method === 'PATCH');
const gets = () => calls.filter((c) => c.method === 'GET' && c.path === '/api/cart');
const byClass = (name, root = content()) => root.all().filter((n) => (n.attrs.class || '').split(' ').includes(name));
const text = (node) => node.textContent.trim().replace(/\s+/g, ' ');

async function open(scriptedLines, withCookie = true) {
    lines = scriptedLines;
    cookie = withCookie ? 'easyco_has_cart=1; XSRF-TOKEN=tok' : '';
    await import(new URL('../../../../resources/js/storefront-cart.js', import.meta.url));
    await flush();
}

// ---------------------------------------------------------------------------------------------------- scenarios

const scenarios = {
    // R1: a first-time visitor (no cart cookie) makes NO request and sees the friendly empty state with the shop button and the category links.
    async empty_state_without_cookie() {
        await open([], false);

        assert.equal(calls.length, 0, 'no request at all without a cart cookie');
        assert.equal(text(byClass('sf-cart-empty-title')[0]), T('cart_empty_title'));
        assert.equal(text(byClass('sf-cart-empty-hint')[0]), T('cart_empty_hint'));
        const button = byClass('sf-cart-empty-button')[0];
        assert.equal(button.tag, 'a');
        assert.equal(button.attrs.href, '/');
        assert.equal(text(button), T('cart_to_shop'));

        const extras = q('[data-sf-cart-empty-extras]');
        assert.equal(extras.hidden, false, 'the category links are shown in the empty state');
        assert.ok(content().all().includes(extras), 'and sit inside the content');
        assert.deepEqual(extras.querySelectorAll('a').map((a) => a.attrs.href), ['/product-category/clothing']);
        assert.equal(byClass('sf-cart-checkout').length, 0, 'no checkout button for an empty cart');
    },

    // The cart with lines: one layout, the stepper, the summary card, the compact promo box, the API hint as it is, and an ENABLED checkout link.
    async cart_with_lines_has_the_summary_the_stepper_and_an_enabled_checkout_link() {
        hint = { text: 'Add 5 € more for free delivery', destinations: ['office'] };
        await open([line(7, 2), line(8, 1)]);

        assert.equal(gets().length, 1);
        assert.equal(byClass('sf-cart-line').length, 2);
        const first = byClass('sf-cart-line')[0];
        assert.equal(byClass('sf-cart-line-link', first)[0].attrs.href, '/product/dress-7', 'the name links to the product');
        assert.deepEqual(byClass('sf-cart-step', first).map((b) => b.attrs['aria-label']), [T('qty_decrease'), T('qty_increase')], 'two stepper buttons with names');
        assert.equal(byClass('sf-cart-qty', first)[0].value, '2');
        assert.equal(byClass('sf-cart-remove', first)[0].tag, 'button');
        assert.equal(text(byClass('sf-cart-remove', first)[0]), T('remove'), 'a text button, not an icon');

        const summary = byClass('sf-cart-summary')[0];
        assert.equal(summary.tag, 'aside');
        const promoInput = summary.querySelector('input');
        assert.equal(promoInput.attrs.placeholder, ' ', 'the compact promo box: the label floats inside the field');
        assert.ok(byClass('sf-promo-compact-field', summary).length === 1, 'built with compact: true');
        assert.equal(text(byClass('sf-cart-shipping-hint', summary)[0]), 'Add 5 € more for free delivery', 'the API sentence exactly');
        assert.equal(text(byClass('sf-cart-shipping-note', summary)[0]), T('cart_shipping_note'));
        assert.equal(byClass('sf-cart-total-discount', summary).length, 0, 'no discount row without a discount');
        assert.equal(text(byClass('sf-cart-total-grand', summary)[0]), `${T('total')}${fmt(3000)}`);

        const checkout = byClass('sf-cart-checkout', summary)[0];
        assert.equal(checkout.tag, 'a', 'a link, not a button');
        assert.equal(checkout.attrs.href, '/checkout');
        assert.equal(checkout.disabled, false);
        assert.equal('disabled' in checkout.attrs, false, 'enabled');
        assert.equal(text(checkout), T('checkout_label'));

        const bar = byClass('sf-cart-bar')[0];
        assert.equal(byClass('sf-cart-checkout', bar)[0].attrs.href, '/checkout', 'the sticky bar carries the same link');
        assert.equal(text(byClass('sf-cart-bar-amount', bar)[0]), fmt(3000));
    },

    // A promotion adds a discount row (the API figures).
    async a_promotion_shows_its_discount_row() {
        promo = 'SAVE';
        await open([line(7, 2)]);

        assert.equal(byClass('sf-cart-total-discount').length, 1);
        assert.equal(text(byClass('sf-cart-total-grand')[0]), `${T('total')}${fmt(1800)}`);
    },

    // The plus button sets the input and runs the SAME handler: ONE PATCH with the new quantity; the page re-renders from the answer.
    async plus_sends_one_patch() {
        await open([line(7, 2)]);
        const plus = byClass('sf-cart-step-plus')[0];

        plus.dispatch('click');
        await flush();

        assert.equal(patches().length, 1);
        assert.deepEqual(patches()[0].body, { quantity: 3 });
        assert.equal(patches()[0].path, '/api/cart/lines/7');
        assert.equal(byClass('sf-cart-qty')[0].value, '3', 're-rendered from the API answer');
    },

    // The input's own change is the other way into the same handler.
    async typing_a_quantity_sends_one_patch() {
        await open([line(7, 2)]);
        const input = byClass('sf-cart-qty')[0];

        input.value = '5';
        input.dispatch('change');
        await flush();

        assert.deepEqual(patches().map((p) => p.body), [{ quantity: 5 }]);
    },

    // Minus at 1 does nothing (removing is the Remove button): no request, the input stays.
    async minus_at_one_sends_nothing() {
        await open([line(7, 1)]);
        const minus = byClass('sf-cart-step-minus')[0];

        assert.equal(minus.attrs['aria-disabled'], 'true');
        minus.dispatch('click');
        await flush();

        assert.equal(patches().length, 0);
        assert.equal(byClass('sf-cart-qty')[0].value, '1');
    },

    // Minus above 1 steps down by one.
    async minus_above_one_steps_down() {
        await open([line(7, 3)]);

        byClass('sf-cart-step-minus')[0].dispatch('click');
        await flush();

        assert.deepEqual(patches().map((p) => p.body), [{ quantity: 2 }]);
    },

    // A value the input refuses (zero, text) is put back and nothing is sent.
    async an_invalid_quantity_is_put_back() {
        await open([line(7, 2)]);
        const input = byClass('sf-cart-qty')[0];

        input.value = '0';
        input.dispatch('change');
        input.value = 'abc';
        input.dispatch('change');
        await flush();

        assert.equal(patches().length, 0);
        assert.equal(input.value, '2');
    },

    // While a request is in flight every control is disabled and the second click sends nothing (no double submit); afterwards they are enabled again.
    async in_flight_disables_the_controls() {
        patchDelay = 150;
        await open([line(7, 2)]);
        const plus = byClass('sf-cart-step-plus')[0];
        const root = q('[data-sf-cart-page]');

        plus.dispatch('click');
        await new Promise((resolve) => setTimeout(resolve, 40));

        assert.equal(root.attrs['aria-busy'], 'true', 'the page is marked busy (the stylesheet dims the lines)');
        const controls = content().querySelectorAll('button, input');
        assert.ok(controls.length >= 5);
        assert.ok(controls.every((c) => c.disabled === true), 'every button and input is disabled while the request runs');

        plus.dispatch('click');
        byClass('sf-cart-remove')[0].dispatch('click');
        await new Promise((resolve) => setTimeout(resolve, 300));

        assert.equal(patches().length, 1, 'no second request');
        assert.equal(calls.filter((c) => c.method === 'DELETE').length, 0);
        assert.equal('aria-busy' in root.attrs, false);
        assert.ok(content().querySelectorAll('button, input').every((c) => c.disabled !== true), 'enabled again');
    },

    // The API cannot be read: the generic message and a retry button that asks again, without reloading the page.
    async a_failure_offers_a_retry() {
        cartReply = { status: 500, data: null };
        await open([line(7, 1)]);

        assert.equal(q('[data-sf-cart-status]').textContent, T('generic_error'));
        const retry = byClass('sf-cart-retry')[0];
        assert.equal(text(retry), T('retry'));
        assert.equal(gets().length, 1);

        cartReply = null;
        retry.dispatch('click');
        await flush();

        assert.equal(gets().length, 2, 'asked again');
        assert.equal(byClass('sf-cart-line').length, 1, 'the cart is shown without a page reload');
        assert.equal(q('[data-sf-cart-status]').textContent, '', 'the message is cleared');
    },
};

assert.ok(scenarios[scenario], `unknown scenario ${scenario}`);
await scenarios[scenario]();
console.log(`OK ${scenario}`);
