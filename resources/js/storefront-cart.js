// The storefront's cart script (stage S3): add to cart, the header count and the cart page. Plain JavaScript, no
// framework. It talks to the EXISTING cart API (/api/cart...) from the browser; the storefront pages themselves are
// cookie-free, so this is the only place a session is ever started, and only when the visitor does something with a cart.
//
// THE SESSION / CSRF FLOW (storefront-design.md §2.8):
//  - the catalog pages set no cookie, so there is no XSRF-TOKEN cookie yet: the first WRITE fetches
//    GET /sanctum/csrf-cookie (it sets XSRF-TOKEN and the session cookie), then sends X-XSRF-TOKEN with the write;
//  - a 419 (a stale token) refreshes the cookie ONCE and repeats the write; a second 419 is shown as a generic error;
//  - R1: GET /api/cart (which starts a session too) is called ONLY when this browser has a cart cookie: an XSRF-TOKEN
//    (it lives as long as the session) OR the `easyco_has_cart` marker (a plain, readable cookie the server sets with the
//    HttpOnly `easyco_cart`, which the script cannot see; it is what lets a guest whose session ended get their cart
//    back). A first-time visitor has neither and costs the server nothing.
//
// Everything that comes from the API is put on the page with textContent, never innerHTML. The only numbers computed
// here are the quantity of items for the header badge (a sum of the API's own quantities) and the decimal point of a
// price (the API's integer minor units written in the shop's own price format, which the server hands over in
// #sf-i18n). Every total, discount and hint is the API's.

var XSRF_COOKIE = 'XSRF-TOKEN';
var CART_MARKER_COOKIE = 'easyco_has_cart';
var i18n = document.getElementById('sf-i18n');

function t(key, vars) {
    var raw = i18n ? i18n.getAttribute('data-m-' + key.replace(/_/g, '-')) : null;
    var text = raw === null ? key : raw;

    if (vars) {
        Object.keys(vars).forEach(function (name) {
            text = text.split(':' + name).join(String(vars[name]));
        });
    }

    return text;
}

function readCookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];

    for (var i = 0; i < parts.length; i++) {
        var at = parts[i].indexOf('=');
        var key = at === -1 ? parts[i] : parts[i].slice(0, at);

        if (decodeURIComponent(key) === name) {
            return at === -1 ? '' : decodeURIComponent(parts[i].slice(at + 1));
        }
    }

    return null;
}

function baseHeaders() {
    return { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
}

/** The R1 gate: does this browser have any sign of a cart? Neither cookie = a first-time visitor = no request at all. */
function hasCartCookie() {
    return Boolean(readCookie(XSRF_COOKIE) || readCookie(CART_MARKER_COOKIE));
}

async function ensureCsrf(force) {
    if (! force && readCookie(XSRF_COOKIE)) {
        return;
    }

    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: baseHeaders() });
}

/** One API call. Resolves {status, data}; rejects only on a network failure. */
async function api(method, path, body) {
    var write = method !== 'GET';

    if (write) {
        await ensureCsrf(false);
    }

    async function attempt() {
        var headers = baseHeaders();

        if (write) {
            headers['X-XSRF-TOKEN'] = readCookie(XSRF_COOKIE) || '';

            if (body !== undefined) {
                headers['Content-Type'] = 'application/json';
            }
        }

        var response = await fetch(path, {
            method: method,
            headers: headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        var data = null;

        if (response.status !== 204) {
            try {
                data = await response.json();
            } catch (e) {
                data = null;
            }
        }

        return { status: response.status, data: data };
    }

    var result = await attempt();

    if (write && result.status === 419) {
        await ensureCsrf(true);
        result = await attempt();
    }

    return result;
}

function el(tag, className, text) {
    var node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined && text !== null) {
        node.textContent = String(text);
    }

    return node;
}

/** A product url from the API: a root-relative path only (never a scheme, never protocol-relative). */
function localUrl(value) {
    return typeof value === 'string' && value.charAt(0) === '/' && value.charAt(1) !== '/' && value.indexOf('\\') === -1 ? value : null;
}

/** The line's image from the API, or null when it is missing or not shaped like one: attributes are set with setAttribute only. */
function lineImage(image) {
    if (! image || typeof image !== 'object') {
        return null;
    }

    var srcPattern = /^(https?:\/\/|\/(?!\/))\S+$/i;
    var srcsetPattern = /^\S+ [0-9]+w(, \S+ [0-9]+w)*$/;

    if (typeof image.src !== 'string' || ! srcPattern.test(image.src) || ! Number.isInteger(image.width) || ! Number.isInteger(image.height)) {
        return null;
    }

    var node = el('img', 'sf-cart-line-img');
    node.setAttribute('src', image.src);

    if (typeof image.srcset === 'string' && srcsetPattern.test(image.srcset)) {
        node.setAttribute('srcset', image.srcset);

        if (typeof image.sizes === 'string') {
            node.setAttribute('sizes', image.sizes);
        }
    }

    node.setAttribute('width', String(image.width));
    node.setAttribute('height', String(image.height));
    node.setAttribute('alt', typeof image.alt === 'string' ? image.alt : '');
    node.setAttribute('loading', 'lazy');
    node.setAttribute('decoding', 'async');

    return node;
}

function message(target, text) {
    if (target) {
        target.textContent = text;
    }
}

function apiMessage(result) {
    return result && result.data && typeof result.data.message === 'string' && result.data.message !== '' ? result.data.message : t('generic_error');
}

// ---------------------------------------------------------------------------------------------- header count

function itemCount(cart) {
    var total = 0;
    var lines = cart && Array.isArray(cart.lines) ? cart.lines : [];

    for (var i = 0; i < lines.length; i++) {
        total += Number(lines[i].quantity) || 0;
    }

    return total;
}

function setCount(cart) {
    var count = document.querySelector('[data-sf-cart-count]');
    var link = document.querySelector('[data-sf-cart-link]');
    var total = itemCount(cart);

    if (! count || ! link) {
        return;
    }

    count.textContent = String(total);
    count.hidden = total === 0;

    if (total > 0) {
        link.setAttribute('aria-label', t('cart_count_label', { count: total }));
    } else {
        link.removeAttribute('aria-label');
    }
}

async function refreshCount() {
    if (! hasCartCookie()) {
        return;
    }

    try {
        var result = await api('GET', '/api/cart');

        if (result.status === 200) {
            setCount(result.data);
        }
    } catch (e) {
        // The badge is a convenience: stay empty.
    }
}

// ------------------------------------------------------------------------------------------------ add to cart

// PB-1: the `purchase` block of the product page: the pickers (colour circles, size buttons), the quantity counter and the add-to-cart button. The page's own <select name="variation"> stays the SOURCE OF TRUTH: a click on a
// choice sets the select and fires the same `change` the rest of the page listens to; without this script the select is shown and nothing here runs. The script reads ONE data attribute (ids and flags: which value of
// which group each variation has, whether it can be bought and how many at most) and never trusts the page for anything the cart endpoint decides: its own validation stays the last word.

var MAX_QUANTITY = 99;

function purchaseData(root) {
    try {
        var data = JSON.parse(root.getAttribute('data-sf-purchase') || '');

        return data && Array.isArray(data.variations) ? data : null;
    } catch (e) {
        return null;
    }
}

function initAddToCart() {
    var box = document.querySelector('[data-sf-add-to-cart]');

    if (! box) {
        return;
    }

    var button = box.querySelector('.sf-add-to-cart');
    var status = box.querySelector('[data-sf-cart-status]');
    var select = document.querySelector('.sf-variation-select');
    var root = document.querySelector('[data-sf-purchase]');
    var data = root ? purchaseData(root) : null;
    var variations = data ? data.variations : [];
    var pickers = !! (data && data.pickers && data.groups > 0);
    var groups = root ? Array.prototype.slice.call(root.querySelectorAll('[data-sf-group]')) : [];
    var selected = groups.map(function () { return null; });
    var qtyInput = root ? root.querySelector('[data-sf-qty-input]') : null;
    var minus = root ? root.querySelector('[data-sf-qty-minus]') : null;
    var plus = root ? root.querySelector('[data-sf-qty-plus]') : null;
    var texts = {
        label: button.getAttribute('data-sf-label') || button.textContent,
        choose: button.getAttribute('data-sf-label-choose') || button.textContent,
        soldOut: button.getAttribute('data-sf-label-sold-out') || button.textContent,
    };
    var anyOrderable = variations.some(function (v) { return v.o === 1; });
    var busy = false;

    function variationById(id) {
        for (var i = 0; i < variations.length; i++) {
            if (variations[i].id === String(id)) {
                return variations[i];
            }
        }

        return null;
    }

    // the chosen variation: all groups chosen (pickers), else the page's select as before (an enabled option), else the only variation of a simple product
    function chosen() {
        if (pickers) {
            if (selected.some(function (v) { return v === null; })) {
                return null;
            }

            var found = null;

            variations.forEach(function (v) {
                if (v.v.every(function (index, g) { return index === selected[g]; }) && (found === null || (v.o === 1 && found.o !== 1))) {
                    found = v;
                }
            });

            return found;
        }

        if (select) {
            var option = select.options[select.selectedIndex];

            return option && ! option.disabled ? variationById(option.value) || { id: option.value, o: 1, q: MAX_QUANTITY } : null;
        }

        var id = button.getAttribute('data-variation-id') || '';

        return id === '' ? null : variationById(id) || { id: id, o: 1, q: MAX_QUANTITY };
    }

    function chosenId() {
        var v = chosen();

        return v && v.o === 1 ? v.id : '';
    }

    // a value is available when some variation that can be bought has it together with what is chosen in the OTHER groups
    function available(g, j) {
        return variations.some(function (v) {
            return v.o === 1 && v.v[g] === j && selected.every(function (s, k) { return k === g || s === null || v.v[k] === s; });
        });
    }

    function maxQuantity() {
        var v = chosen();

        return v === null ? MAX_QUANTITY : Math.max(1, Math.min(MAX_QUANTITY, v.q));
    }

    function clampQuantity(value) {
        var n = parseInt(String(value).replace(/\D/g, ''), 10);

        return isNaN(n) || n < 1 ? 1 : Math.min(n, maxQuantity());
    }

    function syncQuantity() {
        if (! qtyInput) {
            return;
        }

        var current = clampQuantity(qtyInput.value);

        if (qtyInput.value !== '' && String(current) !== qtyInput.value) {
            qtyInput.value = String(current);
        }

        minus.disabled = busy || current <= 1;
        plus.disabled = busy || current >= maxQuantity();
    }

    function drawPickers() {
        groups.forEach(function (group, g) {
            var radios = Array.prototype.slice.call(group.querySelectorAll('[role=radio]'));
            var firstEnabled = null;

            radios.forEach(function (radio) {
                var j = Number(radio.getAttribute('data-sf-value'));
                var on = selected[g] === j;
                var off = ! available(g, j);

                radio.setAttribute('aria-checked', on ? 'true' : 'false');
                radio.setAttribute('aria-disabled', off ? 'true' : 'false');
                radio.classList.toggle('is-unavailable', off);
                off || firstEnabled !== null || (firstEnabled = radio);
            });

            radios.forEach(function (radio) {
                var on = radio.getAttribute('aria-checked') === 'true';

                radio.setAttribute('tabindex', on || (selected[g] === null && radio === firstEnabled) ? '0' : '-1');
            });

            var dropdown = group.querySelector('[data-sf-select]');

            if (dropdown) {
                Array.prototype.forEach.call(dropdown.options, function (option) {
                    option.value === '' || (option.disabled = ! available(g, Number(option.value)));
                });
                dropdown.value = selected[g] === null ? '' : String(selected[g]);
            }
        });
    }

    function drawButton() {
        var v = chosen();
        var state = 'normal';

        if (variations.length > 0 && ! anyOrderable) {
            state = 'sold_out';
        } else if (pickers && v === null) {
            state = 'choose';
        } else if (chosenId() === '') {
            state = 'sold_out';
        }

        button.textContent = state === 'normal' ? texts.label : (state === 'choose' ? texts.choose : texts.soldOut);
        button.setAttribute('data-variation-id', chosenId());
        button.setAttribute('data-sf-state', state);
        button.setAttribute('aria-disabled', state === 'normal' && ! busy ? 'false' : 'true');
        // «choose» stays clickable (the click shows what is missing); every other state that cannot buy is disabled
        button.disabled = busy || state === 'sold_out' || (state === 'normal' && chosenId() === '');
    }

    function sync() {
        pickers && drawPickers();
        syncQuantity();
        drawButton();
    }

    function setSelect(v) {
        if (! select) {
            return;
        }

        if (v) {
            select.value = v.id;
        } else {
            select.selectedIndex = -1;
        }

        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function choose(g, j) {
        if (j !== null && ! available(g, j)) {
            return;
        }

        selected[g] = j;

        // a choice in another group that this one has made impossible is dropped
        selected.forEach(function (s, k) {
            if (k !== g && s !== null && ! available(k, s)) {
                selected[k] = null;
            }
        });

        groups.forEach(function (group) { group.classList.remove('is-missing'); });
        setSelect(chosen());
        sync();
    }

    function colourise() {
        root && Array.prototype.forEach.call(root.querySelectorAll('.sf-swatch[data-colour]'), function (swatch) {
            var colour = swatch.getAttribute('data-colour') || '';
            var dot = swatch.querySelector('.sf-swatch-dot');

            // validated again: only `#rrggbb` ever reaches a style
            dot && /^#[0-9a-f]{6}$/.test(colour) && (dot.style.backgroundColor = colour);
        });
    }

    function pickerEvents() {
        groups.forEach(function (group, g) {
            var radios = function () { return Array.prototype.slice.call(group.querySelectorAll('[role=radio]')); };

            radios().forEach(function (radio) {
                radio.addEventListener('click', function () { choose(g, Number(radio.getAttribute('data-sf-value'))); });
                radio.addEventListener('keydown', function (event) {
                    var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

                    if (! step) {
                        return;
                    }

                    event.preventDefault();

                    var list = radios();
                    var index = list.indexOf(radio);

                    for (var n = 1; n <= list.length; n++) {
                        var next = list[(index + step * n + list.length * n) % list.length];

                        if (next.getAttribute('aria-disabled') !== 'true') {
                            next.focus();
                            choose(g, Number(next.getAttribute('data-sf-value')));

                            return;
                        }
                    }
                });
            });

            var dropdown = group.querySelector('[data-sf-select]');

            dropdown && dropdown.addEventListener('change', function () { choose(g, dropdown.value === '' ? null : Number(dropdown.value)); });
        });
    }

    if (pickers) {
        colourise();
        pickerEvents();
        // nothing is chosen yet: the button asks for a choice
        select && (select.selectedIndex = -1);
    }

    if (select && ! pickers) {
        select.addEventListener('change', function () {
            message(status, chosenId() === '' ? t('choice_unavailable') : '');
            sync();
        });
    }

    if (qtyInput) {
        qtyInput.addEventListener('input', function () {
            var digits = qtyInput.value.replace(/\D/g, '');

            qtyInput.value = digits === '' ? '' : String(clampQuantity(digits));
            syncQuantity();
        });
        qtyInput.addEventListener('change', function () { qtyInput.value = String(clampQuantity(qtyInput.value)); sync(); });
        minus.addEventListener('click', function () { qtyInput.value = String(Math.max(1, clampQuantity(qtyInput.value) - 1)); sync(); });
        plus.addEventListener('click', function () { qtyInput.value = String(Math.min(maxQuantity(), clampQuantity(qtyInput.value) + 1)); sync(); });
    }

    button.addEventListener('click', async function () {
        if (button.getAttribute('data-sf-state') === 'choose') {
            var missing = groups.filter(function (group, g) { return selected[g] === null; })[0];

            missing && missing.classList.add('is-missing');

            return;
        }

        var id = chosenId();

        if (busy || id === '') {
            return;
        }

        busy = true;
        sync();
        message(status, t('adding'));

        try {
            var result = await api('POST', '/api/cart/lines', { variation_id: id, quantity: qtyInput ? clampQuantity(qtyInput.value) : 1 });

            if (result.status === 201 || result.status === 200) {
                status.textContent = t('added') + ' ';

                var link = el('a', 'sf-cart-status-link', t('view_cart'));
                link.setAttribute('href', i18n ? i18n.getAttribute('data-cart-url') : '/cart');
                status.appendChild(link);
                setCount(result.data);
            } else if (result.status === 422) {
                message(status, apiMessage(result));
            } else {
                message(status, t('generic_error'));
            }
        } catch (e) {
            message(status, t('generic_error'));
        }

        busy = false;
        sync();
    });

    sync();
}

// -------------------------------------------------------------------------------------------------- cart page

function formatMoney(money) {
    if (! money || typeof money.minor !== 'number') {
        return '';
    }

    var template = i18n ? i18n.getAttribute('data-price-template') : null;
    var zero = i18n ? i18n.getAttribute('data-price-zero') : null;
    var currency = i18n ? i18n.getAttribute('data-price-currency') : null;
    var dot = zero === null ? -1 : zero.indexOf('.');
    var decimals = zero === null || dot === -1 ? 0 : zero.length - dot - 1;
    var abs = Math.abs(money.minor);
    var factor = Math.pow(10, decimals);
    var amount = decimals === 0 ? String(abs) : String(Math.floor(abs / factor)) + '.' + String(abs % factor).padStart(decimals, '0');
    var text;

    if (template === null || zero === null || money.currency !== currency) {
        text = amount + ' ' + money.currency;
    } else {
        text = template.replace(zero, amount);
    }

    return money.minor < 0 ? '-' + text : text;
}

/**
 * The promo code box (apply / applied + remove / invalid + remove), ONE implementation for the cart page and the checkout summary. It builds
 * the markup from `cart.promotion` and hands every API call to `act(call, successText)`: the page decides what a call means (lock, reload,
 * re-quote, show an error). The apply form submits on Enter and never reaches any other form.
 *
 * `options.compact` (the checkout summary and, since S3-V, the cart page's summary; the plain call keeps the markup it always had): the code field
 * takes the whole width with the promo text as a FLOATING LABEL (a one-space placeholder, the label drawn inside the field by CSS) and the Apply button is attached
 * to the field's right end inside one wrapper, so the two read as one control. The applied / invalid / Remove states are the same in both modes.
 */
function promotionBox(cart, act, options) {
    var compact = !! (options && options.compact);
    var box = el('div', compact ? 'sf-cart-promo sf-promo-compact' : 'sf-cart-promo');
    var promotion = cart.promotion;

    if (promotion && promotion.code) {
        var applied = el('p', promotion.valid ? 'sf-cart-promo-applied' : 'sf-cart-promo-invalid');
        applied.textContent = promotion.valid ? t('promo_applied', { code: promotion.code }) : t('promo_invalid', { code: promotion.code });
        box.appendChild(applied);

        var removeCode = el('button', 'sf-cart-promo-remove', t('promo_remove'));
        removeCode.setAttribute('type', 'button');
        removeCode.addEventListener('click', function () {
            act(function () {
                return api('DELETE', '/api/cart/promotion');
            }, t('promo_removed'));
        });
        box.appendChild(removeCode);

        return box;
    }

    var form = el('form', compact ? 'sf-promo-compact-form' : 'sf-cart-promo-form');
    var inputId = 'sf-promo-code';
    var label = el('label', compact ? 'sf-promo-compact-label' : 'sf-cart-line-caption', t('promo_label'));
    label.setAttribute('for', inputId);
    var code = el('input', compact ? 'sf-promo-compact-input' : 'sf-cart-promo-input');
    code.setAttribute('id', inputId);
    code.setAttribute('type', 'text');
    code.setAttribute('maxlength', '64');
    code.setAttribute('autocomplete', 'off');
    code.setAttribute('autocapitalize', 'characters');
    var apply = el('button', compact ? 'sf-promo-compact-apply' : 'sf-cart-promo-apply', t('promo_apply'));
    apply.setAttribute('type', 'submit');
    form.appendChild(label);

    if (compact) {
        var field = el('div', 'sf-promo-compact-field');
        // The label FLOATS inside the field (CSS only, the checkout's own look): the one-space placeholder is what its :placeholder-shown test needs.
        code.setAttribute('placeholder', ' ');
        field.appendChild(code);
        field.appendChild(apply);
        form.appendChild(field);
    } else {
        form.appendChild(code);
        form.appendChild(apply);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var value = code.value.trim();

        if (value === '') {
            return;
        }

        act(function () {
            return api('PUT', '/api/cart/promotion', { code: value });
        }, null);
    });
    box.appendChild(form);

    return box;
}

function initCartPage() {
    var root = document.querySelector('[data-sf-cart-page]');

    if (! root) {
        return;
    }

    var content = root.querySelector('[data-sf-cart-content]');
    var status = root.querySelector('[data-sf-cart-status]');
    var loading = root.querySelector('[data-sf-cart-loading]');
    var categoryLinks = root.querySelector('[data-sf-cart-empty-extras]');
    var working = false;

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function labelOf(line) {
        var values = [];
        var attributes = Array.isArray(line.attributes) ? line.attributes : [];

        for (var i = 0; i < attributes.length; i++) {
            values.push(attributes[i].value);
        }

        return values.join(' / ');
    }

    /** Runs one API action with the page locked; a success re-renders from the cart the API returned (or a fresh GET). */
    async function act(call, successText) {
        if (working) {
            return;
        }

        working = true;
        root.setAttribute('aria-busy', 'true');
        var buttons = content.querySelectorAll('button, input');

        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = true;
        }

        try {
            var result = await call();
            var ok = result.status >= 200 && result.status < 300;

            if (ok && result.status !== 204 && result.data) {
                render(result.data);
                setCount(result.data);
                message(status, successText);
            } else if (ok) {
                await reload(successText);
            } else {
                message(status, result.status === 422 || result.status === 404 ? apiMessage(result) : t('generic_error'));
                await reload(null, true);
            }
        } catch (e) {
            message(status, t('generic_error'));
            await reload(null, true);
        }

        working = false;
        root.removeAttribute('aria-busy');
    }

    async function reload(successText, keepMessage) {
        try {
            var result = await api('GET', '/api/cart');

            if (result.status === 200) {
                render(result.data);
                setCount(result.data);

                if (successText) {
                    message(status, successText);
                }

                return;
            }
        } catch (e) {
            // fall through
        }

        if (! keepMessage) {
            message(status, t('generic_error'));
        }
    }

    /**
     * One line of the cart: the picture, the name (a link when the API gives a root-relative url), the variation, the unit price, the quantity
     * STEPPER ([-] [number] [+], 44px targets in the stylesheet), the line total and a TEXT «Премахни» button. The stepper's two buttons set the input
     * and run the SAME handler the input's own `change` runs (`onQuantityChange`): one code path, one request.
     */
    function lineItem(line) {
        var item = el('li', 'sf-cart-line');
        var picture = lineImage(line.image);

        if (picture) {
            var pictureBox = el('div', 'sf-cart-line-image');
            pictureBox.appendChild(picture);
            item.appendChild(pictureBox);
        }

        var info = el('div', 'sf-cart-line-info');
        var nameText = line.product_name === null || line.product_name === undefined ? line.sku : line.product_name;
        var name = el('p', 'sf-cart-line-name');
        var href = localUrl(line.product_url);

        if (href) {
            var nameLink = el('a', 'sf-cart-line-link', nameText);
            nameLink.setAttribute('href', href);
            name.appendChild(nameLink);
        } else {
            name.textContent = String(nameText);
        }

        info.appendChild(name);

        var label = labelOf(line);

        if (label !== '') {
            info.appendChild(el('p', 'sf-cart-line-label', label));
        }

        if (line.price_available === false) {
            info.appendChild(el('p', 'sf-cart-line-unavailable', t('unpriced')));
        } else if (line.price_changed_since_add) {
            info.appendChild(el('p', 'sf-cart-line-note', t('price_changed')));
        }

        if (line.price_available !== false) {
            var unit = el('p', 'sf-cart-line-unit');
            unit.appendChild(el('span', 'sf-cart-line-caption', t('unit_price') + ': '));
            unit.appendChild(el('span', 'sf-cart-line-amount', formatMoney(line.unit_price)));
            info.appendChild(unit);
        }

        item.appendChild(info);

        var quantityId = 'sf-qty-' + String(line.variation_id);
        var maxQuantity = Number.isInteger(line.max_quantity) && line.max_quantity > 0 ? line.max_quantity : null;
        var quantity = el('div', 'sf-cart-line-quantity');
        var quantityLabel = el('label', 'sf-cart-line-caption', t('quantity'));
        quantityLabel.setAttribute('for', quantityId);
        var stepper = el('div', 'sf-cart-stepper');
        var minus = el('button', 'sf-cart-step sf-cart-step-minus', '−');
        minus.setAttribute('type', 'button');
        minus.setAttribute('aria-label', t('qty_decrease'));
        var input = el('input', 'sf-cart-qty');
        input.setAttribute('id', quantityId);
        input.setAttribute('type', 'number');
        input.setAttribute('min', '1');
        input.setAttribute('step', '1');
        input.setAttribute('inputmode', 'numeric');

        if (maxQuantity !== null) {
            input.setAttribute('max', String(maxQuantity));
        }

        input.value = String(line.quantity);
        var plus = el('button', 'sf-cart-step sf-cart-step-plus', '+');
        plus.setAttribute('type', 'button');
        plus.setAttribute('aria-label', t('qty_increase'));

        if (line.quantity <= 1) {
            minus.setAttribute('aria-disabled', 'true');
        }

        // THE ONE quantity handler: the input's own `change`, and (after setting the input) the two buttons.
        var onQuantityChange = function () {
            var next = parseInt(input.value, 10);

            if (isNaN(next) || next < 1) {
                input.value = String(line.quantity);

                return;
            }

            if (maxQuantity !== null && next > maxQuantity) {
                input.value = String(line.quantity);

                return;
            }

            if (next !== line.quantity) {
                act(function () {
                    return api('PATCH', '/api/cart/lines/' + encodeURIComponent(String(line.variation_id)), { quantity: next });
                }, t('updated'));
            }
        };
        input.addEventListener('change', onQuantityChange);
        minus.addEventListener('click', function () {
            var current = parseInt(input.value, 10);

            if (isNaN(current) || current <= 1) {
                return;
            }

            input.value = String(current - 1);
            onQuantityChange();
        });
        plus.addEventListener('click', function () {
            var current = parseInt(input.value, 10);

            if (isNaN(current) || current < 1) {
                current = line.quantity;
            }

            if (maxQuantity !== null && current >= maxQuantity) {
                return;
            }

            input.value = String(current + 1);
            onQuantityChange();
        });
        stepper.appendChild(minus);
        stepper.appendChild(input);
        stepper.appendChild(plus);
        quantity.appendChild(quantityLabel);
        quantity.appendChild(stepper);
        item.appendChild(quantity);

        if (line.price_available !== false) {
            var total = el('p', 'sf-cart-line-total');
            total.appendChild(el('span', 'sf-cart-line-caption', t('line_total') + ': '));
            total.appendChild(el('span', 'sf-cart-line-amount', formatMoney(line.line_total)));
            item.appendChild(total);
        }

        var remove = el('button', 'sf-cart-remove', t('remove'));
        remove.setAttribute('type', 'button');
        remove.addEventListener('click', function () {
            act(function () {
                return api('DELETE', '/api/cart/lines/' + encodeURIComponent(String(line.variation_id)));
            }, t('removed'));
        });
        item.appendChild(remove);

        return item;
    }

    /** The money rows of the summary: the subtotal and, only when there is one, the discount. Every figure is the API's. */
    function totals(cart) {
        var list = el('dl', 'sf-cart-totals');

        function row(caption, money, className) {
            var wrap = el('div', 'sf-cart-total-row ' + (className || ''));
            wrap.appendChild(el('dt', 'sf-cart-total-caption', caption));
            wrap.appendChild(el('dd', 'sf-cart-total-amount', formatMoney(money)));
            list.appendChild(wrap);
        }

        row(t('subtotal'), cart.subtotal);

        if (cart.promotion && cart.promotion.valid && cart.promotion.discount_amount) {
            var discount = cart.promotion.discount_amount;
            row(t('discount', { code: cart.promotion.code }), { minor: -Math.abs(discount.minor), currency: discount.currency }, 'sf-cart-total-discount');
        }

        return list;
    }

    function grandTotal(cart) {
        var list = el('dl', 'sf-cart-totals sf-cart-totals-grand');
        var wrap = el('div', 'sf-cart-total-row sf-cart-total-grand');
        wrap.appendChild(el('dt', 'sf-cart-total-caption', t('total')));
        wrap.appendChild(el('dd', 'sf-cart-total-amount', formatMoney(cart.total)));
        list.appendChild(wrap);

        return list;
    }

    /** The empty cart: a friendly title and sentence, a primary button to the shop and the top-level category links (server-rendered, no query). */
    function renderEmpty() {
        var box = el('div', 'sf-cart-empty');
        box.appendChild(el('h2', 'sf-cart-empty-title', t('cart_empty_title')));
        box.appendChild(el('p', 'sf-cart-empty-hint', t('cart_empty_hint')));
        var shop = el('a', 'sf-cart-empty-button', t('cart_to_shop'));
        shop.setAttribute('href', i18n ? (i18n.getAttribute('data-home-url') || '/') : '/');
        box.appendChild(shop);
        content.appendChild(box);

        if (categoryLinks) {
            categoryLinks.hidden = false;
            content.appendChild(categoryLinks);
        }
    }

    function render(cart) {
        clear(content);

        if (loading) {
            loading.hidden = true;
        }

        var lines = cart && Array.isArray(cart.lines) ? cart.lines : [];

        if (lines.length === 0) {
            renderEmpty();

            return;
        }

        // ONE column on a phone, two from 900px: the lines on the left and the summary card on the right (a sticky card).
        var layout = el('div', 'sf-cart-layout');
        var list = el('ul', 'sf-cart-lines');
        list.setAttribute('aria-label', t('cart_lines'));

        lines.forEach(function (line) {
            list.appendChild(lineItem(line));
        });
        layout.appendChild(list);

        var summary = el('aside', 'sf-cart-summary');
        summary.appendChild(el('h2', 'sf-cart-summary-title', t('cart_summary')));
        // The checkout's own compact promo box (a floating label inside the field, Apply attached): the same function, the same markup.
        summary.appendChild(promotionBox(cart, act, { compact: true }));
        summary.appendChild(totals(cart));

        if (cart.free_shipping_hint && typeof cart.free_shipping_hint.text === 'string' && cart.free_shipping_hint.text !== '') {
            // The API's own sentence, shown as it is (no arithmetic here).
            summary.appendChild(el('p', 'sf-cart-shipping-hint', cart.free_shipping_hint.text));
        }

        summary.appendChild(el('p', 'sf-cart-shipping-note', t('cart_shipping_note')));
        summary.appendChild(grandTotal(cart));

        // C1: the checkout is a page now (the same cookie-free storefront group); the cart page just links to it.
        var checkoutUrl = localUrl(i18n ? i18n.getAttribute('data-checkout-url') : null) || '/checkout';
        var checkout = el('a', 'sf-cart-checkout', t('checkout_label'));
        checkout.setAttribute('href', checkoutUrl);
        summary.appendChild(checkout);
        layout.appendChild(summary);
        content.appendChild(layout);

        // On a phone the same link is also a bar that sticks to the bottom of the screen with the total (CSS `position: sticky`, hidden from 900px).
        var bar = el('div', 'sf-cart-bar');
        var barTotal = el('p', 'sf-cart-bar-total');
        barTotal.appendChild(el('span', 'sf-cart-bar-caption', t('total')));
        barTotal.appendChild(el('span', 'sf-cart-bar-amount', formatMoney(cart.total)));
        var barLink = el('a', 'sf-cart-checkout sf-cart-bar-button', t('checkout_label'));
        barLink.setAttribute('href', checkoutUrl);
        bar.appendChild(barTotal);
        bar.appendChild(barLink);
        content.appendChild(bar);
    }

    /** The API could not be read: the generic message and a button that asks again (no page reload). */
    function renderFailure() {
        clear(content);

        var retry = el('button', 'sf-cart-retry', t('retry'));
        retry.setAttribute('type', 'button');
        retry.addEventListener('click', function () {
            message(status, '');
            start();
        });
        content.appendChild(retry);
    }

    async function start() {
        if (! hasCartCookie()) {
            // R1: neither cookie means no cart was ever touched in this browser: do not start a session just to show "empty".
            render(null);
            setCount(null);

            return;
        }

        try {
            var result = await api('GET', '/api/cart');

            if (result.status === 200) {
                render(result.data);
                setCount(result.data);

                return;
            }
        } catch (e) {
            // fall through
        }

        if (loading) {
            loading.hidden = true;
        }

        message(status, t('generic_error'));
        renderFailure();
    }

    start();
}

initAddToCart();
initCartPage();

// The cart page and the checkout page read the cart themselves (and set the header count from it).
if (! document.querySelector('[data-sf-cart-page]') && ! document.querySelector('[data-sf-checkout]')) {
    refreshCount();
}

// What the checkout script (storefront-checkout.js) reuses: ONE implementation of the cookie gate, the API call with its
// CSRF handling, the price format and the safe DOM helpers.
export { api, apiMessage, el, formatMoney, hasCartCookie, lineImage, localUrl, message, promotionBox, setCount, t };
