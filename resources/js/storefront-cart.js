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

function initAddToCart() {
    var box = document.querySelector('[data-sf-add-to-cart]');

    if (! box) {
        return;
    }

    var button = box.querySelector('.sf-add-to-cart');
    var status = box.querySelector('[data-sf-cart-status]');
    var select = document.querySelector('.sf-variation-select');
    var busy = false;

    function chosenId() {
        if (select) {
            var option = select.options[select.selectedIndex];

            return option && ! option.disabled ? option.value : '';
        }

        return button.getAttribute('data-variation-id') || '';
    }

    function sync() {
        var id = chosenId();

        button.setAttribute('data-variation-id', id);
        button.disabled = busy || id === '';
    }

    if (select) {
        select.addEventListener('change', function () {
            message(status, chosenId() === '' ? t('choice_unavailable') : '');
            sync();
        });
    }

    button.addEventListener('click', async function () {
        var id = chosenId();

        if (busy || id === '') {
            return;
        }

        busy = true;
        sync();
        message(status, t('adding'));

        try {
            var result = await api('POST', '/api/cart/lines', { variation_id: id, quantity: 1 });

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

function initCartPage() {
    var root = document.querySelector('[data-sf-cart-page]');

    if (! root) {
        return;
    }

    var content = root.querySelector('[data-sf-cart-content]');
    var status = root.querySelector('[data-sf-cart-status]');
    var loading = root.querySelector('[data-sf-cart-loading]');
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

        item.appendChild(info);

        if (line.price_available !== false) {
            var unit = el('p', 'sf-cart-line-unit');
            unit.appendChild(el('span', 'sf-cart-line-caption', t('unit_price') + ': '));
            unit.appendChild(el('span', 'sf-cart-line-amount', formatMoney(line.unit_price)));
            item.appendChild(unit);
        }

        var quantityId = 'sf-qty-' + String(line.variation_id);
        var quantity = el('div', 'sf-cart-line-quantity');
        var quantityLabel = el('label', 'sf-cart-line-caption', t('quantity'));
        quantityLabel.setAttribute('for', quantityId);
        var input = el('input', 'sf-cart-qty');
        input.setAttribute('id', quantityId);
        input.setAttribute('type', 'number');
        input.setAttribute('min', '1');
        input.setAttribute('step', '1');
        input.setAttribute('inputmode', 'numeric');
        input.value = String(line.quantity);
        input.addEventListener('change', function () {
            var next = parseInt(input.value, 10);

            if (isNaN(next) || next < 1) {
                input.value = String(line.quantity);

                return;
            }

            if (next !== line.quantity) {
                act(function () {
                    return api('PATCH', '/api/cart/lines/' + encodeURIComponent(String(line.variation_id)), { quantity: next });
                }, t('updated'));
            }
        });
        quantity.appendChild(quantityLabel);
        quantity.appendChild(input);
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

    function promotionBox(cart) {
        var box = el('div', 'sf-cart-promo');
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

        var form = el('form', 'sf-cart-promo-form');
        var inputId = 'sf-promo-code';
        var label = el('label', 'sf-cart-line-caption', t('promo_label'));
        label.setAttribute('for', inputId);
        var code = el('input', 'sf-cart-promo-input');
        code.setAttribute('id', inputId);
        code.setAttribute('type', 'text');
        code.setAttribute('maxlength', '64');
        code.setAttribute('autocomplete', 'off');
        code.setAttribute('autocapitalize', 'characters');
        var apply = el('button', 'sf-cart-promo-apply', t('promo_apply'));
        apply.setAttribute('type', 'submit');
        form.appendChild(label);
        form.appendChild(code);
        form.appendChild(apply);
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

        row(t('total'), cart.total, 'sf-cart-total-grand');

        return list;
    }

    function render(cart) {
        clear(content);

        if (loading) {
            loading.hidden = true;
        }

        var lines = cart && Array.isArray(cart.lines) ? cart.lines : [];

        if (lines.length === 0) {
            content.appendChild(el('p', 'sf-cart-empty', t('cart_empty_text')));

            return;
        }

        var list = el('ul', 'sf-cart-lines');
        list.setAttribute('aria-label', t('cart_lines'));

        lines.forEach(function (line) {
            list.appendChild(lineItem(line));
        });
        content.appendChild(list);
        content.appendChild(promotionBox(cart));
        content.appendChild(totals(cart));

        if (cart.free_shipping_hint && typeof cart.free_shipping_hint.text === 'string' && cart.free_shipping_hint.text !== '') {
            content.appendChild(el('p', 'sf-cart-shipping-hint', cart.free_shipping_hint.text));
        }

        var checkout = el('button', 'sf-cart-checkout', t('checkout_label'));
        checkout.setAttribute('type', 'button');
        checkout.disabled = true;
        content.appendChild(checkout);
        content.appendChild(el('p', 'sf-cart-checkout-note', t('checkout_note')));
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
    }

    start();
}

initAddToCart();
initCartPage();

if (! document.querySelector('[data-sf-cart-page]')) {
    refreshCount();
}
