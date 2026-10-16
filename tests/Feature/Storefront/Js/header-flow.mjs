// Behavioural check of the storefront HEADER script (resources/js/storefront.js, stage D2: the drawer, the search row, the accordion) without a browser and without a
// new dependency: the REAL rendered home page is parsed into a tiny fake DOM and the REAL script runs against it with a scripted fetch.
//
//   node header-flow.mjs <rendered-home.html> <scenario>
//
// Exit code 0 and the line "OK <scenario>" when every assertion holds; the stack of the first failed assertion otherwise.
// Run by tests/Feature/Storefront/StorefrontHeaderFlowTest.php (skipped when node is not installed).
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

// ------------------------------------------------------------------------------------------------ a small selector engine and the DOM bits the script uses

function classesOf(node) {
    return (node.attrs.class || '').split(/\s+/).filter(Boolean);
}

/** One compound selector (no combinator): tag, #id, .class, [attr], [attr="v"], :not([attr]), :not([attr="v"]). */
function matchesCompound(node, compound) {
    let rest = compound;
    const tag = /^[a-z][a-z0-9-]*/i.exec(rest);

    if (tag) {
        if (node.tag !== tag[0].toLowerCase()) return false;
        rest = rest.slice(tag[0].length);
    }

    while (rest.length) {
        let m;

        if ((m = /^#([\w-]+)/.exec(rest))) { if (node.id !== m[1]) return false; }
        else if ((m = /^\.([\w-]+)/.exec(rest))) { if (! classesOf(node).includes(m[1])) return false; }
        else if ((m = /^:not\(\[([\w-]+)(?:="([^"]*)")?\]\)/.exec(rest))) { if (m[1] in node.attrs && (m[2] === undefined || node.attrs[m[1]] === m[2])) return false; }
        else if ((m = /^\[([\w-]+)(?:="([^"]*)")?\]/.exec(rest))) { if (! (m[1] in node.attrs) || (m[2] !== undefined && node.attrs[m[1]] !== m[2])) return false; }
        else throw new Error(`unsupported selector part: ${rest}`);

        rest = rest.slice(m[0].length);
    }

    return true;
}

function matchesSelector(node, selector) {
    return selector.split(',').some((part) => {
        const compounds = part.trim().split(/\s+/);
        let current = node;

        if (! matchesCompound(current, compounds.pop())) return false;

        while (compounds.length) {
            const want = compounds.pop();
            current = current.parent;

            while (current && current.tag !== '#root' && ! matchesCompound(current, want)) current = current.parent;

            if (! current || current.tag === '#root') return false;
        }

        return true;
    });
}

FakeNode.prototype.matches = function (selector) { return matchesSelector(this, selector); };
FakeNode.prototype.closest = function (selector) {
    let node = this;

    while (node && node.tag !== '#root') {
        if (matchesSelector(node, selector)) return node;
        node = node.parent;
    }

    return null;
};
FakeNode.prototype.contains = function (other) {
    for (let node = other; node; node = node.parent) if (node === this) return true;

    return false;
};
FakeNode.prototype.hasAttribute = function (name) { return name in this.attrs; };
FakeNode.prototype.focus = function () { globalThis.document.activeElement = this; };
Object.defineProperty(FakeNode.prototype, 'classList', {
    get() {
        const node = this;

        return {
            add: (c) => { const list = classesOf(node); if (! list.includes(c)) { list.push(c); node.attrs.class = list.join(' '); } },
            remove: (c) => { node.attrs.class = classesOf(node).filter((x) => x !== c).join(' '); },
            contains: (c) => classesOf(node).includes(c),
            toggle: (c, force) => { const has = classesOf(node).includes(c); const want = force === undefined ? ! has : force; want ? node.classList.add(c) : node.classList.remove(c); return want; },
        };
    },
});
// `hidden` is a property in the fake DOM and an attribute in the script: keep them one thing.
const originalSetAttribute = FakeNode.prototype.setAttribute;
FakeNode.prototype.setAttribute = function (k, v) { if (k === 'hidden') this.hidden = true; originalSetAttribute.call(this, k, v); };
const originalRemoveAttribute = FakeNode.prototype.removeAttribute;
FakeNode.prototype.removeAttribute = function (k) { if (k === 'hidden') this.hidden = false; originalRemoveAttribute.call(this, k); };
const originalHasAttribute = FakeNode.prototype.hasAttribute;
FakeNode.prototype.hasAttribute = function (name) { return name === 'hidden' ? this.hidden === true : originalHasAttribute.call(this, name); };

// ------------------------------------------------------------------------------------------------ the environment (the home page)

const [, , htmlPath, scenario] = process.argv;
const dom = parse(readFileSync(htmlPath, 'utf8'));
const listeners = {};
const documentElement = new FakeNode('html');

globalThis.document = {
    cookie: '',
    activeElement: null,
    documentElement,
    getElementById: (id) => dom.querySelector(`#${id}`),
    querySelector: (s) => dom.querySelector(s),
    querySelectorAll: (s) => dom.querySelectorAll(s),
    createElement: (tag) => new FakeNode(tag),
    addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
};
globalThis.window = { location: { href: 'http://shop.test/', origin: 'http://shop.test' } };
globalThis.fetch = async () => ({ status: 204, json: async () => null });

// The REAL script (it imports the cart and checkout scripts, which stand down on a page without their roots).
await import(new URL('../../../../resources/js/storefront.js', import.meta.url));

const q = (s) => dom.querySelector(s);
const html = () => documentElement;
const active = () => globalThis.document.activeElement;

function fire(type, target, extra = {}) {
    const event = { type, target, key: extra.key, shiftKey: Boolean(extra.shift), defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    (listeners[type] || []).forEach((fn) => fn(event));

    return event;
}

const click = (node) => fire('click', node);
const press = (key, shift = false) => fire('keydown', active(), { key, shift });
const toggles = () => dom.querySelectorAll('[data-sf-menu-toggle]');
const focusables = () => q('.sf-drawer-panel').querySelectorAll('a[href], button:not([disabled]), input:not([disabled])');

// ---------------------------------------------------------------------------------------------------- scenarios

const scenarios = {
    // The script marks the page as scripted; the drawer and its toggles are in the page and closed.
    async the_drawer_starts_closed() {
        assert.ok(html().classList.contains('js'));
        const drawer = q('#sf-drawer');
        assert.ok(drawer, 'the drawer is in the page');
        assert.equal(drawer.classList.contains('sf-drawer-open'), false);
        assert.ok(toggles().length >= 1);
        assert.ok(toggles().every((t) => t.getAttribute('aria-expanded') === 'false' && t.getAttribute('aria-controls') === 'sf-drawer'));
    },

    // Open: aria-expanded true on every toggle, the page scroll is locked, focus moves INTO the drawer (its first focusable, the close button).
    async opening_moves_focus_in_locks_scroll_and_sets_aria() {
        const toggle = toggles()[0];
        toggle.focus();
        click(toggle);

        assert.ok(q('#sf-drawer').classList.contains('sf-drawer-open'));
        assert.ok(html().classList.contains('sf-locked'), 'the page does not scroll behind it');
        assert.ok(toggles().every((t) => t.getAttribute('aria-expanded') === 'true'));
        assert.ok(q('.sf-drawer-panel').contains(active()), 'focus is inside the drawer');
        assert.ok(active().classList.contains('sf-drawer-close'));
    },

    // Tab and Shift+Tab never leave the open drawer.
    async the_focus_is_trapped_while_open() {
        const toggle = toggles()[0];
        click(toggle);
        const items = focusables();
        assert.ok(items.length >= 2);

        items[items.length - 1].focus();
        const forward = press('Tab');
        assert.equal(forward.defaultPrevented, true, 'Tab on the last item is taken over');
        assert.equal(active(), items[0], '...and goes to the first');

        const back = press('Tab', true);
        assert.equal(back.defaultPrevented, true);
        assert.equal(active(), items[items.length - 1], 'Shift+Tab on the first goes to the last');

        // A Tab from somewhere outside the drawer is pulled back in.
        toggle.focus();
        press('Tab');
        assert.ok(q('.sf-drawer-panel').contains(active()));
    },

    // Esc closes it, unlocks the page, resets aria-expanded and gives focus back to the toggle that opened it.
    async escape_closes_and_returns_focus_to_the_toggle() {
        const toggle = toggles()[0];
        toggle.focus();
        click(toggle);
        press('Escape');

        assert.equal(q('#sf-drawer').classList.contains('sf-drawer-open'), false);
        assert.equal(html().classList.contains('sf-locked'), false);
        assert.ok(toggles().every((t) => t.getAttribute('aria-expanded') === 'false'));
        assert.equal(active(), toggle, 'focus is back on the toggle');
    },

    // The overlay and the close button close it too; the toggle itself closes an open drawer.
    async the_overlay_the_close_button_and_the_toggle_close_it() {
        const toggle = toggles()[0];
        const open = () => q('#sf-drawer').classList.contains('sf-drawer-open');

        click(toggle); assert.equal(open(), true);
        click(q('.sf-drawer-overlay')); assert.equal(open(), false, 'the overlay');

        click(toggle); assert.equal(open(), true);
        click(q('.sf-drawer-close')); assert.equal(open(), false, 'the close button');

        click(toggle); assert.equal(open(), true);
        click(toggle); assert.equal(open(), false, 'the toggle again');
        assert.equal(active(), toggle);
    },

    // The accordion: a button per parent, aria-expanded and the list it controls follow.
    async the_category_accordion_expands_and_collapses() {
        const button = q('[data-sf-acc-toggle]');
        assert.ok(button, 'the fixture has a category with children');
        const panel = q(`#${button.getAttribute('aria-controls')}`);

        assert.equal(button.getAttribute('aria-expanded'), 'false');
        assert.equal(panel.classList.contains('sf-acc-open'), false);

        click(button);
        assert.equal(button.getAttribute('aria-expanded'), 'true');
        assert.equal(panel.classList.contains('sf-acc-open'), true);

        click(button);
        assert.equal(button.getAttribute('aria-expanded'), 'false');
        assert.equal(panel.classList.contains('sf-acc-open'), false);
    },

    // The search row (appears because the page was rendered with a search route): opens under the header, focus goes to its field, Esc closes it and returns focus.
    async the_search_row_opens_focuses_its_field_and_escape_returns_focus() {
        const icon = q('[data-sf-search-toggle]');
        const row = q('#sf-search-row');
        assert.ok(icon && row, 'the search icon and the row are in the page (a search route exists)');
        assert.equal(row.hasAttribute('hidden'), true);
        assert.equal(icon.getAttribute('aria-controls'), 'sf-search-row');

        icon.focus();
        click(icon);
        assert.equal(row.hasAttribute('hidden'), false);
        assert.equal(icon.getAttribute('aria-expanded'), 'true');
        assert.equal(active().tag, 'input', 'focus is in the field');

        press('Escape');
        assert.equal(row.hasAttribute('hidden'), true);
        assert.equal(icon.getAttribute('aria-expanded'), 'false');
        assert.equal(active(), icon, 'focus is back on the icon');
    },

    // Esc with nothing open does nothing (and does not throw).
    async escape_with_nothing_open_is_harmless() {
        const event = press('Escape');
        assert.equal(event.defaultPrevented, false);
        assert.equal(q('#sf-drawer').classList.contains('sf-drawer-open'), false);
    },
};

assert.ok(scenarios[scenario], `unknown scenario ${scenario}`);
await scenarios[scenario]();
console.log(`OK ${scenario}`);
