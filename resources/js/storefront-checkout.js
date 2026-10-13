// The storefront's checkout script (stage C1, C1b): drives the one-page checkout on the EXISTING API (GET /api/cart,
// POST /api/shipping/quote, POST /api/checkout). Plain JavaScript, no framework. It reuses storefront-cart.js for the cookie
// gate, the API call with its CSRF handling, the price format and the safe DOM helpers: ONE implementation of each.
//
// Everything that comes from the API is put on the page with textContent / setAttribute, never as markup. Prices are the
// API's own minor units, written in the shop's price format; the only arithmetic is the display sum of the goods the quote
// reports plus the delivery price the customer chose (the order total is goods + shipping, domain rule), the same sum the
// order will carry. The page never prices or decides "free" itself: it shows what the API answered.
//
// The form's markup and labels are server-rendered (resources/views/storefront/checkout.blade.php); this script fills the
// delivery choices, the summary and the totals, validates what the browser can, and submits.
//
// Delivery (storefront-design.md 2.11): ONE request, `delivery_type: "all"`, answers every kind of destination of every
// courier (shipping-domain-design.md 9.3.4). Before a place is typed it is a PREVIEW (indicative prices, no handles, no order);
// with a place it is FINAL. The choice is a (method, kind) pair: first the COURIER, then, if it serves more than one kind, the
// KIND from a native <select> ("To an office - 3.00 EUR"). Switching among already-quoted options needs no new request.
import { api, apiMessage, el, formatMoney, hasCartCookie, lineImage, localUrl, message, promotionBox, setCount, t } from './storefront-cart.js';

var DEBOUNCE_MS = 500;
var STREET_FIELDS = ['postal_code', 'address_line_1', 'address_line_2'];
var PICKUP_FIELDS = ['pickup_point_reference', 'pickup_point_name', 'pickup_point_address'];
var STALE_QUOTE_REASONS = ['shipping_price_changed', 'shipping_quote_expired', 'shipping_method_unavailable'];
var KIND_ORDER = ['address', 'office', 'locker'];
var REGION_MODES = ['optional', 'required', 'hidden'];
var NOTE_MAX = 1000;
var NOTE_WARN_AT = 0.9;
// A field the API names that has no input of its own is shown where the customer can act on it.
var FIELD_ALIAS = { settlement: 'city', carrier_code: 'shipping_method_id', pickup_point_kind: 'shipping_method_id' };

/** A country code the way PHP's strtoupper(trim()) reads it: ASCII letters only are upper-cased. */
function normalizeCountry(country) {
    return String(country).trim().replace(/[a-z]/g, function (letter) {
        return letter.toUpperCase();
    });
}

/**
 * The region rule for a country (checkout RN-1): the country's own entry wins, else the default; the code is compared after the
 * same normalisation PHP applies. EXACTLY CheckoutRegionPolicy::modeFor (a static test feeds both the same table of cases).
 */
function regionModeFor(policy, country) {
    var code = normalizeCountry(country);
    var fallback = policy && REGION_MODES.indexOf(policy.default) !== -1 ? policy.default : 'optional';
    var countries = policy && policy.countries && typeof policy.countries === 'object' ? policy.countries : {};

    if (Object.prototype.hasOwnProperty.call(countries, code) && REGION_MODES.indexOf(countries[code]) !== -1) {
        return countries[code];
    }

    return fallback;
}

/** The length of a text in Unicode CODE POINTS (an emoji is one), the way the server counts the note. */
function codePoints(text) {
    return Array.from(String(text));
}

function isPickupKind(kind) {
    return kind === 'office' || kind === 'locker';
}

function initCheckout(root) {
    var i18n = document.getElementById('sf-i18n');
    var form = root.querySelector('[data-sf-checkout-form]');
    var loading = root.querySelector('[data-sf-checkout-loading]');
    var summaryLines = root.querySelector('[data-sf-summary-lines]');
    var summaryTotals = root.querySelector('[data-sf-summary-totals]');
    var summaryPanel = root.querySelector('#sf-summary-panel');
    var summaryToggle = root.querySelector('[data-sf-summary-toggle]');
    var summaryToggleLabel = root.querySelector('[data-sf-summary-toggle-label]');
    var summaryToggleTotal = root.querySelector('[data-sf-summary-toggle-total]');
    var quoteStatus = root.querySelector('[data-sf-quote-status]');
    var hintLine = root.querySelector('[data-sf-shipping-hint]');
    var courierBox = root.querySelector('[data-sf-courier-box]');
    var courierList = root.querySelector('[data-sf-couriers]');
    var kindBox = root.querySelector('[data-sf-type-box]');
    var kindSelect = root.querySelector('[data-sf-kind-select]');
    var kindText = root.querySelector('[data-sf-kind-text]');
    var errorSummary = root.querySelector('[data-sf-error-summary]');
    var statusLine = root.querySelector('[data-sf-checkout-status]');
    var placeOrder = root.querySelector('[data-sf-place-order]');
    var addressBox = root.querySelector('[data-sf-address-fields]');
    var pickupBox = root.querySelector('[data-sf-pickup-fields]');
    var regionBox = root.querySelector('[data-sf-region-field]');
    var noteCounter = root.querySelector('[data-sf-note-counter]');
    var promoHost = root.querySelector('[data-sf-promo]');
    var promoError = root.querySelector('[data-sf-promo-error]');

    var cartUrl = localUrl(i18n ? i18n.getAttribute('data-cart-url') : null) || '/cart';
    var regionPolicy = { default: 'optional', countries: {} };

    try {
        var rawPolicy = root.getAttribute('data-region-policy');

        if (rawPolicy) {
            regionPolicy = JSON.parse(rawPolicy);
        }
    } catch (e) {
        // an unreadable policy is "optional": the server stays the authority and answers a 422 on `region` when it is required
    }

    var cart = null;
    var cartId = null;
    var quote = null;          // the last `all` answer (it carries the goods figure and whether it is a preview)
    var couriers = [];         // [{key, name, fromMinor, currency, options: [{id, kind, method, dest, price}]}]
    var selection = null;      // {id, handle, priceMinor, currency, kind, carrier, final}
    var chosenCourier = null;  // the key of the courier the customer picked
    var preferredCourier = null; // the (courier, method, kind) the customer last chose: kept across re-quotes while still offered
    var preferredMethod = null;
    var preferredKind = null;
    var timer = null;
    var quoteInFlight = false;
    var quoteDirty = false;    // the destination changed while a quote was running: that answer is stale
    var quotePending = false;  // a new quote is scheduled or running: the chosen handle may be outdated
    var submitting = false;
    var promoBusy = false;     // a promo request (and the reload and re-quote it starts) is running
    var promoCompare = null;   // the chosen option and its price before a promo change: the first fresh quote is compared with it
    var announcedMinor = null; // the delivery price the status line last announced as changed (until the next press)

    function fieldValue(name) {
        var node = form.elements[name];

        return node && typeof node.value === 'string' ? node.value.trim() : '';
    }

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    /** The kind of the chosen option: 'address' | 'office' | 'locker' | null (nothing chosen yet). */
    function deliveryKind() {
        return selection === null ? null : selection.kind;
    }

    /** What the API calls it: 'street_address' | 'pickup_point' | null. */
    function deliveryType() {
        var kind = deliveryKind();

        if (kind === null) {
            return null;
        }

        return isPickupKind(kind) ? 'pickup_point' : 'street_address';
    }

    function kindLabel(kind) {
        var known = { address: 'ck_kind_address', office: 'ck_kind_office', locker: 'ck_kind_locker' };

        return known[kind] ? t(known[kind]) : '';
    }

    function pointFieldsFilled() {
        return PICKUP_FIELDS.every(function (name) {
            return fieldValue(name) !== '';
        });
    }

    function refreshPlaceOrder() {
        // Never while a quote is unfinished, before a FINAL (non-preview) price is chosen, or while a pick-up point is half typed.
        placeOrder.disabled = submitting || promoBusy || cart === null || selection === null || quotePending || ! selection.final || (isPickupKind(selection.kind) && ! pointFieldsFilled());
    }

    function setStatus(text, state) {
        message(statusLine, text);

        if (state) {
            statusLine.setAttribute('data-state', state);
        } else {
            statusLine.removeAttribute('data-state');
        }
    }

    // ------------------------------------------------------------------------------------------ errors

    function clearErrors() {
        errorSummary.hidden = true;
        clear(errorSummary);

        root.querySelectorAll('[data-sf-error-for]').forEach(function (node) {
            node.hidden = true;
            node.textContent = '';
        });

        form.querySelectorAll('[aria-invalid]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        });
    }

    /** The summary at the top takes focus (role="alert"); every item links to its field. */
    function showSummary(items) {
        clear(errorSummary);
        errorSummary.appendChild(el('p', 'sf-error-summary-title', t('ck_errors_intro')));

        var list = el('ul', 'sf-error-summary-list');

        items.forEach(function (item) {
            var li = el('li', 'sf-error-summary-item');

            if (item.field && form.elements[item.field] && form.elements[item.field].id) {
                var link = el('a', '', item.text);
                link.setAttribute('href', '#' + form.elements[item.field].id);
                li.appendChild(link);
            } else {
                li.textContent = item.text;
            }

            list.appendChild(li);
        });

        errorSummary.appendChild(list);
        errorSummary.hidden = false;
        errorSummary.focus();
    }

    function fieldErrors(errors) {
        var items = [];

        Object.keys(errors).forEach(function (name) {
            var field = FIELD_ALIAS[name] || name;
            var messages = Array.isArray(errors[name]) ? errors[name] : [errors[name]];
            var text = String(messages[0] === undefined ? '' : messages[0]);
            var holder = root.querySelector('[data-sf-error-for="' + field.replace(/[^a-z_0-9]/gi, '') + '"]');
            var input = form.elements[field];

            if (holder) {
                holder.textContent = text;
                holder.hidden = false;
            }

            if (input && holder && typeof input.setAttribute === 'function') {
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', holder.id);
            }

            items.push({ field: field, text: text });
        });

        return items;
    }

    function clearFieldError(field) {
        var holder = root.querySelector('[data-sf-error-for="' + field + '"]');

        if (holder) {
            holder.hidden = true;
            holder.textContent = '';
        }
    }

    // ------------------------------------------------------------------------------------------- cart

    function renderSummary() {
        clear(summaryLines);

        (cart && Array.isArray(cart.lines) ? cart.lines : []).forEach(function (line) {
            var li = el('li', 'sf-summary-line');
            var picture = lineImage(line.image);

            if (picture) {
                var box = el('div', 'sf-summary-line-image');
                box.appendChild(picture);
                li.appendChild(box);
            }

            var info = el('div', 'sf-summary-line-info');
            info.appendChild(el('p', 'sf-summary-line-name', t('ck_item_count', { count: line.quantity, name: line.product_name === null || line.product_name === undefined ? line.sku : line.product_name })));

            var values = [];
            (Array.isArray(line.attributes) ? line.attributes : []).forEach(function (attribute) {
                values.push(attribute.value);
            });

            if (values.length > 0) {
                info.appendChild(el('p', 'sf-summary-line-label', values.join(' / ')));
            }

            li.appendChild(info);
            li.appendChild(el('p', 'sf-summary-line-total', formatMoney(line.line_total)));
            summaryLines.appendChild(li);
        });

        renderPromo();
        renderTotals();
    }

    // ------------------------------------------------------------------------------------------- promo code

    /** The cart page's promo box (ONE implementation, storefront-cart.js), in the summary: it only ever fills its own host, never the form. */
    function renderPromo() {
        clear(promoHost);
        promoHost.hidden = cart === null;

        if (cart !== null) {
            promoHost.appendChild(promotionBox(cart, runPromo));
        }
    }

    function lockPromo(locked) {
        promoHost.querySelectorAll('button, input').forEach(function (node) {
            node.disabled = locked;
        });
    }

    /**
     * What a promo call means on the checkout: lock the box and "Place order", ask, then on success reload the cart (the summary and the
     * totals follow) and ask for ONE fresh `all` quote (the goods total moved, thresholds and handles may too; the (courier, method, kind)
     * is kept by renderQuote when still offered). The form's fields are never touched. An error stays under the field.
     */
    async function runPromo(call) {
        if (promoBusy || submitting) {
            return;
        }

        promoBusy = true;
        promoError.hidden = true;
        promoError.textContent = '';
        lockPromo(true);
        refreshPlaceOrder();

        try {
            var result = await call();

            if (result.status >= 200 && result.status < 300) {
                promoCompare = selection !== null && selection.final ? { id: selection.id, kind: selection.kind, minor: selection.priceMinor } : null;

                // The goods figure of the old quote is stale now: until the new quote the totals use the cart's own total.
                if (quote !== null) {
                    quote.goods_after_discount = null;
                }

                if (await loadCart()) {
                    scheduleQuote(true);
                }
            } else {
                promoError.textContent = apiMessage(result);
                promoError.hidden = false;
            }
        } catch (e) {
            promoError.textContent = t('generic_error');
            promoError.hidden = false;
        }

        promoBusy = false;
        lockPromo(false);
        refreshPlaceOrder();
    }

    function renderTotals() {
        clear(summaryTotals);

        if (cart === null) {
            summaryToggleTotal.textContent = '';

            return;
        }

        function row(caption, text, className) {
            var wrap = el('div', 'sf-cart-total-row ' + (className || ''));
            wrap.appendChild(el('dt', 'sf-cart-total-caption', caption));
            wrap.appendChild(el('dd', 'sf-cart-total-amount', text));
            summaryTotals.appendChild(wrap);
        }

        row(t('subtotal'), formatMoney(cart.subtotal));

        if (cart.promotion && cart.promotion.valid && cart.promotion.discount_amount) {
            var discount = cart.promotion.discount_amount;
            row(t('discount', { code: cart.promotion.code }), formatMoney({ minor: -Math.abs(discount.minor), currency: discount.currency }), 'sf-cart-total-discount');
        }

        var goods = quote && quote.goods_after_discount ? quote.goods_after_discount : cart.total;

        // A PREVIEW price is indicative: it is never summed into a total the customer could take for the final one.
        if (selection === null || ! selection.final) {
            row(t('ck_delivery'), t('ck_to_be_chosen'));
            row(t('total'), formatMoney(goods) + ' + ' + t('ck_delivery').toLowerCase(), 'sf-cart-total-grand');
            summaryToggleTotal.textContent = formatMoney(goods);

            return;
        }

        var grand = formatMoney({ minor: goods.minor + selection.priceMinor, currency: goods.currency });

        row(t('ck_delivery'), selection.priceMinor === 0 ? t('ck_free') : formatMoney({ minor: selection.priceMinor, currency: selection.currency }));
        row(t('total'), grand, 'sf-cart-total-grand');
        // The collapsed bar on a narrow screen carries the same total and follows the delivery choice.
        summaryToggleTotal.textContent = grand;
    }

    async function loadCart() {
        if (! hasCartCookie()) {
            window.location.replace(cartUrl);

            return false;
        }

        try {
            var result = await api('GET', '/api/cart');

            if (result.status !== 200 || ! result.data || ! Array.isArray(result.data.lines) || result.data.lines.length === 0 || result.data.cart_id === null) {
                window.location.replace(cartUrl);

                return false;
            }

            cart = result.data;
            cartId = result.data.cart_id;
            setCount(cart);
            renderSummary();
            refreshPlaceOrder();

            return true;
        } catch (e) {
            message(quoteStatus, t('generic_error'));

            return false;
        }
    }

    // ------------------------------------------------------------------------------------ region and note

    function regionMode() {
        return regionModeFor(regionPolicy, fieldValue('country'));
    }

    /** The region field follows the policy of the CURRENT country: optional, required (marker + client check) or hidden (cleared, not sent). */
    function applyRegionMode() {
        var mode = regionMode();
        var street = deliveryKind() === 'address';
        var input = form.elements.region;

        regionBox.hidden = mode === 'hidden';
        input.required = street && mode === 'required';

        if (mode === 'required') {
            regionBox.setAttribute('data-required', 'true');
        } else {
            regionBox.removeAttribute('data-required');
        }

        if (mode === 'hidden') {
            input.value = '';
            clearFieldError('region');
        }
    }

    function updateNoteCounter() {
        var note = form.elements.customer_note;
        var chars = codePoints(note.value);

        // The browser's maxlength counts UTF-16 units, so it can only be stricter than the server; this is the code-point guard.
        if (chars.length > NOTE_MAX) {
            chars = chars.slice(0, NOTE_MAX);
            note.value = chars.join('');
        }

        noteCounter.textContent = t('ck_note_counter', { count: chars.length, max: NOTE_MAX });

        if (chars.length >= NOTE_MAX * NOTE_WARN_AT) {
            noteCounter.setAttribute('data-state', 'warning');
        } else {
            noteCounter.removeAttribute('data-state');
        }
    }

    // ----------------------------------------------------------------------------------- delivery options

    /** What the chosen kind asks for: the street fields (and the region), the pick-up point's fields, or nothing yet. */
    function toggleDestination() {
        var kind = deliveryKind();
        var street = kind === 'address';
        var pickup = isPickupKind(kind);

        addressBox.hidden = ! street;
        pickupBox.hidden = ! pickup;

        form.elements.address_line_1.required = street;
        PICKUP_FIELDS.forEach(function (name) {
            form.elements[name].required = pickup;
        });

        applyRegionMode();
    }

    /** A pick-up point belongs to ONE courier and kind: when either changes, what was typed for it is dropped. */
    function clearPickupFields() {
        PICKUP_FIELDS.forEach(function (name) {
            form.elements[name].value = '';
            clearFieldError(name);
        });
    }

    /** The ONE request: every kind of every courier. No place yet = a preview; the pick-up kind is never sent with `all`. */
    function quoteBody() {
        var country = fieldValue('country');

        if (country.length !== 2) {
            return null;
        }

        var body = { delivery_type: 'all', country: country };
        var place = fieldValue('city');

        if (place !== '') {
            body.settlement = place;

            if (fieldValue('postal_code') !== '') {
                body.postal_code = fieldValue('postal_code');
            }
        }

        return body;
    }

    function clearOptions(statusText) {
        quote = null;
        promoCompare = null;
        selection = null;
        couriers = [];
        chosenCourier = null;
        quotePending = false;
        clear(courierList);
        clear(kindSelect);
        courierBox.hidden = true;
        kindBox.hidden = true;
        hintLine.hidden = true;
        hintLine.textContent = '';
        message(quoteStatus, statusText || '');
        toggleDestination();
        renderTotals();
        refreshPlaceOrder();
    }

    function scheduleQuote(immediately) {
        quotePending = true;
        refreshPlaceOrder();
        window.clearTimeout(timer);
        timer = window.setTimeout(runQuote, immediately ? 0 : DEBOUNCE_MS);
    }

    async function fetchQuote(body) {
        try {
            var result = await api('POST', '/api/shipping/quote', body);

            if (result.status === 200 && result.data) {
                return { data: result.data, text: null };
            }

            if (result.status === 422 && result.data && typeof result.data.message === 'string') {
                return { data: null, text: result.data.message };
            }
        } catch (e) {
            // falls through: an unreadable answer is the generic message
        }

        return { data: null, text: null };
    }

    async function runQuote() {
        var body = quoteBody();

        if (body === null) {
            clearOptions(t('ck_enter_country'));

            return;
        }

        if (quoteInFlight) {
            // One request at a time: remember that the destination moved on; the running answer is stale.
            quoteDirty = true;

            return;
        }

        quoteInFlight = true;
        message(quoteStatus, t('ck_loading_options'));

        var result = await fetchQuote(body);

        quoteInFlight = false;

        if (quoteDirty) {
            quoteDirty = false;
            runQuote();

            return;
        }

        if (result.data !== null) {
            renderQuote(result.data);
        } else {
            clearOptions(result.text || t('generic_error'));
        }
    }

    // ---- the model: couriers, each with the (method, kind) options the one answer offers

    function buildCouriers(data) {
        var list = [];
        var byKey = {};
        var other = null;
        var byId = {};

        (data.methods || []).forEach(function (method) {
            byId[String(method.id)] = method;
        });

        var groups = data.groups && data.groups.length > 0
            ? data.groups
            : [{ courier: null, methods: (data.methods || []).map(function (method) { return method.id; }), from_minor: null, currency: data.currency }];

        function courierFor(name) {
            if (name === null || name === undefined || String(name).trim() === '') {
                if (other === null) {
                    other = { key: '', name: null, fromMinor: null, currency: data.currency, options: [] };
                }

                return other;
            }

            var key = String(name).trim().toLowerCase();

            if (! byKey[key]) {
                byKey[key] = { key: key, name: String(name).trim(), fromMinor: null, currency: data.currency, options: [] };
                list.push(byKey[key]);
            }

            return byKey[key];
        }

        groups.forEach(function (group) {
            var courier = courierFor(group.courier);

            if (typeof group.from_minor === 'number' && (courier.fromMinor === null || group.from_minor < courier.fromMinor)) {
                courier.fromMinor = group.from_minor;
                courier.currency = group.currency || data.currency;
            }

            (group.methods || []).forEach(function (id) {
                var method = byId[String(id)];

                if (! method || ! method.destinations || typeof method.destinations !== 'object') {
                    return;
                }

                KIND_ORDER.forEach(function (kind) {
                    var dest = method.destinations[kind];

                    if (dest) {
                        courier.options.push({ id: String(method.id), kind: kind, method: method, dest: dest, price: dest.available && dest.price ? dest.price : null });
                    }
                });
            });
        });

        if (other !== null) {
            list.push(other);
        }

        list.forEach(function (courier) {
            courier.options.sort(function (a, b) {
                return KIND_ORDER.indexOf(a.kind) - KIND_ORDER.indexOf(b.kind);
            });
        });

        return list.filter(function (courier) {
            return courier.options.length > 0;
        });
    }

    /** The options of a courier the customer can actually pick: unavailable kinds are NOT offered (see storefront-design.md 2.11). */
    function available(courier) {
        return courier.options.filter(function (option) {
            return option.price !== null;
        });
    }

    function priceText(price) {
        return price.minor === 0 ? t('ck_free') : formatMoney(price);
    }

    function findCourier(key) {
        for (var n = 0; n < couriers.length; n++) {
            if (couriers[n].key === key) {
                return couriers[n];
            }
        }

        return null;
    }

    function courierTitle(courier) {
        return courier.name === null ? t('ck_courier_other') : courier.name;
    }

    function previewing() {
        return quote !== null && quote.preview === true;
    }

    /** The status line under the place fields, from the state alone: what the customer can do next. */
    function statusForState() {
        if (couriers.length === 0) {
            return t('ck_no_methods');
        }

        if (previewing()) {
            return t('ck_preview_note');
        }

        if (selection !== null) {
            return '';
        }

        return chosenCourier === null ? t('ck_choose_courier') : t('ck_choose_type');
    }

    function afterChoice(keepStatus) {
        toggleDestination();
        renderTotals();
        refreshPlaceOrder();

        if (! keepStatus) {
            message(quoteStatus, statusForState());
        }
    }

    function selectOption(courier, option) {
        // carrier_code is the chosen courier; a method without one is named by itself (the API requires the field).
        selection = {
            id: option.id,
            handle: option.dest.handle === undefined ? null : option.dest.handle,
            priceMinor: option.price.minor,
            currency: option.price.currency,
            kind: option.kind,
            carrier: courier.name === null ? option.method.name : courier.name,
            final: ! previewing(),
        };
        preferredCourier = courier.key;
        preferredMethod = option.id;
        preferredKind = option.kind;
        clearFieldError('shipping_method_id');
    }

    function radioRow(name, value, checked, disabled, text, onChange) {
        var label = el('label', 'sf-choice');
        var radio = el('input');
        radio.setAttribute('type', 'radio');
        radio.setAttribute('name', name);
        radio.setAttribute('value', value);
        radio.checked = checked;
        radio.disabled = disabled;
        radio.addEventListener('change', onChange);
        label.appendChild(radio);
        label.appendChild(el('span', '', ' ' + text));

        return label;
    }

    function optionKey(option) {
        return option.id + ':' + option.kind;
    }

    /** The kinds of the chosen courier: a native <select> for two or more, plain text for exactly one, nothing without a courier. */
    function renderKinds() {
        var courier = chosenCourier === null ? null : findCourier(chosenCourier);
        var options = courier === null ? [] : available(courier);

        clear(kindSelect);

        if (options.length === 0) {
            kindBox.hidden = true;

            return;
        }

        kindBox.hidden = false;

        if (options.length === 1) {
            kindSelect.hidden = true;
            kindText.hidden = false;
            kindText.textContent = kindLabel(options[0].kind) + ' — ' + priceText(options[0].price);

            return;
        }

        kindText.hidden = true;
        kindText.textContent = '';
        kindSelect.hidden = false;

        var placeholder = el('option', '', t('ck_choose_type'));
        placeholder.setAttribute('value', '');
        kindSelect.appendChild(placeholder);

        options.forEach(function (option) {
            // Two methods of ONE courier serving the same kind are told apart by the method's name.
            var twins = options.filter(function (other) { return other.kind === option.kind; }).length > 1;
            var text = kindLabel(option.kind) + (twins ? ' (' + option.method.name + ')' : '') + ' — ' + priceText(option.price);
            var node = el('option', '', text);
            node.setAttribute('value', optionKey(option));
            kindSelect.appendChild(node);
        });

        kindSelect.value = selection === null ? '' : selection.id + ':' + selection.kind;
    }

    function renderCouriers() {
        clear(courierList);

        couriers.forEach(function (courier) {
            var options = available(courier);
            var text = courierTitle(courier);

            if (options.length === 0) {
                text += ' — ' + t('ck_unavailable');
            } else if (options.length === 1 && ! previewing()) {
                // ONE kind: the courier's own row says which and what it costs, and no second choice is asked.
                text += ' — ' + kindLabel(options[0].kind) + ' — ' + priceText(options[0].price);
            } else if (courier.fromMinor !== null) {
                text += ' — ' + t('ck_from', { price: priceText({ minor: courier.fromMinor, currency: courier.currency }) });
            }

            courierList.appendChild(radioRow('courier', courier.key, chosenCourier === courier.key, options.length === 0, text, function () {
                chooseCourier(courier);
            }));
        });

        courierBox.hidden = couriers.length === 0;
    }

    /** A courier was picked: its kind is kept when it serves it, picked for the customer when it serves only one, else asked. */
    function chooseCourier(courier) {
        var wanted = preferredKind;
        var options = available(courier);
        var keep = options.filter(function (option) { return option.kind === wanted; })[0];

        chosenCourier = courier.key;
        preferredCourier = courier.key;
        preferredMethod = null;
        selection = null;
        clearPickupFields();

        if (keep) {
            selectOption(courier, keep);
        } else if (options.length === 1) {
            selectOption(courier, options[0]);
        } else {
            preferredKind = null;
        }

        renderKinds();
        afterChoice();
    }

    function chooseKind(key) {
        var courier = chosenCourier === null ? null : findCourier(chosenCourier);
        var option = courier === null ? undefined : available(courier).filter(function (candidate) { return optionKey(candidate) === key; })[0];

        selection = null;
        preferredMethod = null;
        preferredKind = null;
        clearPickupFields();

        if (option) {
            selectOption(courier, option);
        }

        afterChoice();
    }

    function renderQuote(data) {
        var previousCourier = preferredCourier;
        var previousMethod = preferredMethod;
        var previousKind = preferredKind;

        quote = data;
        quotePending = false;
        selection = null;
        chosenCourier = null;
        couriers = buildCouriers(data);

        var hint = data.free_shipping_hint;

        hintLine.textContent = hint && typeof hint.text === 'string' ? hint.text : '';
        hintLine.hidden = ! (hint && typeof hint.text === 'string' && hint.text !== '');

        // A choice made before is kept when this answer still offers that (method, kind) (re-captured with the NEW handle and
        // price) and is cleared, with a short message, when it does not. Nothing is ever swapped for another option silently.
        var gone = false;
        var courier = previousCourier === null ? null : findCourier(previousCourier);

        if (previousCourier !== null && courier === null) {
            gone = true;
            preferredCourier = null;
            preferredMethod = null;
            preferredKind = null;
        } else if (courier !== null) {
            chosenCourier = courier.key;

            if (previousMethod !== null) {
                var kept = available(courier).filter(function (option) {
                    return option.id === previousMethod && option.kind === previousKind;
                })[0];

                if (kept) {
                    selectOption(courier, kept);
                } else {
                    gone = true;
                    preferredMethod = null;
                    preferredKind = null;
                }
            } else if (available(courier).length === 1) {
                selectOption(courier, available(courier)[0]);
            }
        }

        renderCouriers();
        renderKinds();
        afterChoice(true);
        message(quoteStatus, gone ? t('ck_choice_gone') : statusForState());

        // Rule (A) after a promo change: the kept choice costs another delivery price now. Shown and announced; nothing is submitted.
        if (promoCompare !== null) {
            if (selection !== null && selection.final && selection.id === promoCompare.id && selection.kind === promoCompare.kind && selection.priceMinor !== promoCompare.minor) {
                announcePrice({ minor: selection.priceMinor, currency: selection.currency });
            }

            promoCompare = null;
        }

        // A fresh quote that disagrees with the price announced after a refusal: the latest figure, one line.
        if (announcedMinor !== null && selection !== null && selection.final && selection.priceMinor !== announcedMinor) {
            announcePrice({ minor: selection.priceMinor, currency: selection.currency });
        }
    }

    // ------------------------------------------------------------------------------------------ submit

    function payload() {
        var pickup = isPickupKind(selection.kind);
        var body = {
            cart_id: cartId,
            email: fieldValue('email'),
            recipient_name: fieldValue('recipient_name'),
            phone: fieldValue('phone'),
            payment_method: form.querySelector('input[name="payment_method"]:checked').value,
            delivery_type: deliveryType(),
            country: fieldValue('country'),
            // The delivery choice exactly as the resolver reads it: the method, the handle the quote issued, the price shown.
            shipping_method_id: selection.id,
            quote_handle: selection.handle,
            expected_shipping_minor: selection.priceMinor,
        };

        if (! pickup) {
            body.city = fieldValue('city');

            STREET_FIELDS.forEach(function (name) {
                if (fieldValue(name) !== '') {
                    body[name] = fieldValue(name);
                }
            });

            // A hidden region is never sent; an empty optional one is omitted.
            if (regionMode() !== 'hidden' && fieldValue('region') !== '') {
                body.region = fieldValue('region');
            }
        } else {
            // The place the pick-up point was quoted for is the point's settlement; the courier is the one the customer chose.
            body.settlement = fieldValue('city');
            body.carrier_code = selection.carrier;
            // The kind the customer chose (a client claim until the courier stage; the handle is bound to it).
            body.pickup_point_kind = selection.kind;

            PICKUP_FIELDS.forEach(function (name) {
                if (fieldValue(name) !== '') {
                    body[name] = fieldValue(name);
                }
            });
        }

        // The note is sent as typed (the server trims it); an empty one is omitted.
        var note = form.elements.customer_note.value;

        if (note.trim() !== '') {
            body.customer_note = note;
        }

        return body;
    }

    /** Only a page of THIS site under /order/received/ is ever navigated to. */
    function confirmationTarget(value) {
        try {
            var url = new URL(String(value), window.location.href);

            return url.origin === window.location.origin && url.pathname.indexOf('/order/received/') === 0 ? url.href : null;
        } catch (e) {
            return null;
        }
    }

    /** Rule (A): a changed delivery price is shown and CONFIRMED by the customer, never resubmitted silently. */
    function adoptFreshPrice(price) {
        selection.priceMinor = price.minor;
        selection.currency = price.currency;

        couriers.forEach(function (courier) {
            courier.options.forEach(function (option) {
                if (option.id === selection.id && option.kind === selection.kind) {
                    option.price = { minor: price.minor, currency: price.currency };
                }
            });
        });

        renderCouriers();
        renderKinds();
        renderTotals();
        announcePrice(price);
    }

    /** The one "price changed" line of the status line (never stacked): shown for the latest figure. */
    function announcePrice(price) {
        announcedMinor = price.minor;
        setStatus(t('ck_price_changed', { price: priceText(price) }), 'price-changed');
    }

    async function submit(event) {
        event.preventDefault();

        if (submitting || cart === null) {
            return;
        }

        clearErrors();
        setStatus('');
        announcedMinor = null;

        if (selection === null) {
            showSummary([{ field: null, text: couriers.length > 0 && chosenCourier === null ? t('ck_choose_courier') : t('ck_choose_method') }]);

            return;
        }

        if (! selection.final) {
            return;
        }

        if (selection.kind === 'address' && regionMode() === 'required' && fieldValue('region') === '') {
            var holder = root.querySelector('[data-sf-error-for="region"]');
            var text = t('ck_region_required');

            holder.textContent = text;
            holder.hidden = false;
            form.elements.region.setAttribute('aria-invalid', 'true');
            form.elements.region.setAttribute('aria-describedby', holder.id);
            showSummary([{ field: 'region', text: text }]);

            return;
        }

        if (! form.checkValidity()) {
            form.reportValidity();

            return;
        }

        submitting = true;
        refreshPlaceOrder();
        setStatus(t('ck_placing'));

        var keepLocked = false;

        try {
            var result = await api('POST', '/api/checkout', payload());

            if (result.status === 201 && result.data) {
                var target = confirmationTarget(result.data.confirmation_url);

                if (target !== null) {
                    keepLocked = true;
                    window.location.replace(target);

                    return;
                }

                setStatus('');
                showSummary([{ field: null, text: t('generic_error') }]);
            } else if (result.status === 422 && result.data && result.data.errors && typeof result.data.errors === 'object') {
                setStatus('');
                showSummary(fieldErrors(result.data.errors));
            } else if (result.status === 422 || result.status === 409 || result.status === 404) {
                setStatus('');
                var reason = result.data && result.data.reason ? result.data.reason : null;
                var price = result.data && result.data.price;

                if (reason === 'shipping_price_changed' && price && Number.isInteger(price.minor) && typeof price.currency === 'string') {
                    // Rule (A): the new figure is on the page and the preference is kept, NOTHING is resent. The old handle is bound to the
                    // old amount, so it is dropped and ONE fresh quote captures the new handle for the same (courier, method, kind);
                    // the button is locked until it ends and the customer presses again.
                    adoptFreshPrice(price);
                    selection.handle = null;
                    scheduleQuote(true);
                } else if (STALE_QUOTE_REASONS.indexOf(reason) !== -1) {
                    // The handle is no longer good: it is dropped and a fresh quote re-captures the SAME (method, kind) when it is
                    // still offered. The customer presses "Place order" again; nothing is resubmitted by itself.
                    showSummary([{ field: null, text: apiMessage(result) }]);
                    selection.handle = null;
                    scheduleQuote(true);
                } else {
                    showSummary([{ field: null, text: apiMessage(result) }]);

                    if (result.status !== 422 || reason === 'empty_cart') {
                        // Stock, price or cart changed: show the cart as it is now (an empty one sends the visitor to /cart).
                        await loadCart();
                        setStatus(t('ck_cart_changed'));
                    }
                }
            } else {
                setStatus('');
                showSummary([{ field: null, text: t('generic_error') }]);
            }
        } catch (e) {
            setStatus('');
            showSummary([{ field: null, text: t('generic_error') }]);
        }

        if (! keepLocked) {
            submitting = false;
            refreshPlaceOrder();
        }
    }

    // --------------------------------------------------------------------------------------------- wiring

    // The destination moved: ask again after a pause. The choice stays on screen (and is re-captured with the new handle when
    // the new answer still offers it) so the fields the customer is typing in never disappear under the cursor; the button
    // stays locked until the new answer is in. Choosing among the options already quoted sends nothing.
    ['country', 'city', 'postal_code'].forEach(function (name) {
        form.elements[name].addEventListener('input', function () {
            if (name === 'country') {
                applyRegionMode();
            }

            scheduleQuote(false);
        });
    });

    PICKUP_FIELDS.forEach(function (name) {
        form.elements[name].addEventListener('input', refreshPlaceOrder);
    });

    kindSelect.addEventListener('change', function () {
        chooseKind(kindSelect.value);
    });

    form.elements.customer_note.addEventListener('input', updateNoteCounter);

    // The mobile bar: the same summary, opened in place.
    summaryToggle.addEventListener('click', function () {
        var open = summaryToggle.getAttribute('aria-expanded') !== 'true';

        summaryToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        summaryPanel.setAttribute('data-open', open ? 'true' : 'false');
        summaryToggleLabel.textContent = open ? t('ck_summary_hide') : t('ck_summary_show');
    });

    form.addEventListener('submit', submit);
    toggleDestination();
    updateNoteCounter();

    loadCart().then(function (ready) {
        if (loading) {
            loading.hidden = true;
        }

        if (ready) {
            scheduleQuote(true);
        }
    });
}

var checkoutRoot = document.querySelector('[data-sf-checkout]');

if (checkoutRoot) {
    initCheckout(checkoutRoot);
}

// The pure region rule, exported so that a test can feed it the same table of cases as the PHP rule.
export { regionModeFor };
