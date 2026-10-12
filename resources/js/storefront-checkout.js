// The storefront's checkout script (stage C1): drives the one-page checkout on the EXISTING API (GET /api/cart,
// POST /api/shipping/quote, POST /api/checkout). Plain JavaScript, no framework. It reuses storefront-cart.js for the cookie
// gate, the API call with its CSRF handling, the price format and the safe DOM helpers: ONE implementation of each.
//
// Everything that comes from the API is put on the page with textContent / setAttribute, never as markup. Prices are the
// API's own minor units, written in the shop's price format; the only arithmetic is the display sum of the goods the quote
// reports plus the delivery price the customer chose (the order total is goods + shipping, domain rule), the same sum the
// order will carry.
//
// The form's markup and labels are server-rendered (resources/views/storefront/checkout.blade.php); this script fills the
// delivery choices, the summary and the totals, validates what the browser can, and submits.
//
// Delivery is chosen COURIER FIRST, then the delivery type (an address, an office, a locker), from the `groups` the quote
// returns (shipping-domain-design.md §6.8). The type decides what is asked next: the street fields for an address, the
// pick-up point's fields for an office or a locker. Because the type is chosen AFTER the courier, the destination is quoted
// both ways (a street address and a pick-up point) from the one place field, and the answers are merged by courier.
import { api, apiMessage, el, formatMoney, hasCartCookie, lineImage, localUrl, message, setCount, t } from './storefront-cart.js';

var DEBOUNCE_MS = 500;
var STREET_FIELDS = ['postal_code', 'address_line_1', 'address_line_2'];
var PICKUP_FIELDS = ['pickup_point_reference', 'pickup_point_name', 'pickup_point_address'];
var STALE_QUOTE_REASONS = ['shipping_price_changed', 'shipping_quote_expired', 'shipping_method_unavailable'];
var TYPE_ORDER = ['address', 'office', 'locker', 'pickup'];
// A field the API names that has no input of its own is shown where the customer can act on it.
var FIELD_ALIAS = { settlement: 'city', carrier_code: 'shipping_method_id' };

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
    var typeBox = root.querySelector('[data-sf-type-box]');
    var typeList = root.querySelector('[data-sf-types]');
    var errorSummary = root.querySelector('[data-sf-error-summary]');
    var statusLine = root.querySelector('[data-sf-checkout-status]');
    var placeOrder = root.querySelector('[data-sf-place-order]');
    var addressBox = root.querySelector('[data-sf-address-fields]');
    var pickupBox = root.querySelector('[data-sf-pickup-fields]');

    var cartUrl = localUrl(i18n ? i18n.getAttribute('data-cart-url') : null) || '/cart';
    var cart = null;
    var cartId = null;
    var quote = null;          // the answer that carries the goods figure (any one of the two quotes)
    var couriers = [];         // [{key, name, options: [{id, type, kind, method}]}], merged from the quotes
    var selection = null;      // {id, handle, priceMinor, currency, kind, carrier}
    var chosenCourier = null;  // the key of the courier the customer picked
    var preferredCourier = null; // courier and method the customer last chose: kept across re-quotes while still offered
    var preferredMethod = null;
    var timer = null;
    var quoteInFlight = false;
    var quoteDirty = false;    // the destination changed while a quote was running: that answer is stale
    var quotePending = false;  // a new quote is scheduled or running: the chosen handle may be outdated
    var submitting = false;

    function fieldValue(name) {
        var node = form.elements[name];

        return node && typeof node.value === 'string' ? node.value.trim() : '';
    }

    /** 'street_address' | 'pickup_point' | null (nothing chosen yet): decided by the chosen delivery option. */
    function deliveryType() {
        return selection === null ? null : selection.kind;
    }

    function typeLabel(code) {
        var known = { address: 'ck_kind_address', office: 'ck_kind_office', locker: 'ck_kind_locker', pickup: 'ck_kind_pickup' };

        return known[code] ? t(known[code]) : '';
    }

    function refreshPlaceOrder() {
        placeOrder.disabled = submitting || cart === null || selection === null || quotePending;
    }

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
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

        renderTotals();
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

        if (selection === null) {
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

    // ----------------------------------------------------------------------------------- delivery options

    /** What the chosen delivery type asks for: the street fields, the pick-up point's fields, or nothing yet. */
    function toggleDestination() {
        var type = deliveryType();
        var street = type === 'street_address';
        var pickup = type === 'pickup_point';

        addressBox.hidden = ! street;
        pickupBox.hidden = ! pickup;

        form.elements.address_line_1.required = street;
        PICKUP_FIELDS.forEach(function (name) {
            form.elements[name].required = pickup;
        });
    }

    function quoteBodies() {
        var place = fieldValue('city');
        var country = fieldValue('country');

        if (place === '' || country === '') {
            return null;
        }

        var street = { delivery_type: 'street_address', country: country, settlement: place };

        if (fieldValue('postal_code') !== '') {
            street.postal_code = fieldValue('postal_code');
        }

        return [street, { delivery_type: 'pickup_point', country: country, settlement: place }];
    }

    function clearOptions(statusText) {
        quote = null;
        selection = null;
        couriers = [];
        chosenCourier = null;
        quotePending = false;
        clear(courierList);
        clear(typeList);
        courierBox.hidden = true;
        typeBox.hidden = true;
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
        var bodies = quoteBodies();

        if (bodies === null) {
            clearOptions(t('ck_enter_city'));

            return;
        }

        if (quoteInFlight) {
            // One request at a time: remember that the destination moved on; the running answer is stale.
            quoteDirty = true;

            return;
        }

        quoteInFlight = true;
        message(quoteStatus, t('ck_loading_options'));

        var street = await fetchQuote(bodies[0]);
        var pickup = quoteDirty ? { data: null, text: null } : await fetchQuote(bodies[1]);

        quoteInFlight = false;

        if (quoteDirty) {
            quoteDirty = false;
            runQuote();

            return;
        }

        if (street.data !== null || pickup.data !== null) {
            renderQuote(street.data, pickup.data);
        } else {
            clearOptions(street.text || pickup.text || t('generic_error'));
        }
    }

    // ---- the merged model: couriers, each with the delivery types it offers

    /** The delivery type a method is offered as for this kind of quote, or null when it does not serve it. */
    function optionType(method, kind) {
        var pickup = kind === 'pickup_point';

        if (method.requires_pickup_point !== pickup) {
            return null;
        }

        var type = method.delivery_type;

        if (type === null || type === undefined || type === 'other') {
            return pickup ? 'pickup' : 'address';
        }

        if (pickup) {
            return type === 'office' || type === 'locker' ? type : null;
        }

        return type === 'address' ? 'address' : null;
    }

    function buildCouriers(streetData, pickupData) {
        var list = [];
        var byKey = {};
        var other = null;

        function courierFor(name) {
            if (name === null || name === undefined || String(name).trim() === '') {
                if (other === null) {
                    other = { key: '', name: null, options: [] };
                }

                return other;
            }

            var key = String(name).trim().toLowerCase();

            if (! byKey[key]) {
                byKey[key] = { key: key, name: String(name).trim(), options: [] };
                list.push(byKey[key]);
            }

            return byKey[key];
        }

        function add(data, kind) {
            if (! data) {
                return;
            }

            var byId = {};
            (data.methods || []).forEach(function (method) {
                byId[method.id] = method;
            });

            var groups = data.groups && data.groups.length > 0
                ? data.groups
                : [{ courier: null, methods: (data.methods || []).map(function (method) { return method.id; }) }];

            groups.forEach(function (group) {
                (group.methods || []).forEach(function (id) {
                    var method = byId[id];
                    var type = method ? optionType(method, kind) : null;

                    if (type !== null) {
                        courierFor(group.courier).options.push({ id: String(method.id), type: type, kind: kind, method: method });
                    }
                });
            });
        }

        add(streetData, 'street_address');
        add(pickupData, 'pickup_point');

        if (other !== null) {
            list.push(other);
        }

        list.forEach(function (courier) {
            courier.options.sort(function (a, b) {
                return TYPE_ORDER.indexOf(a.type) - TYPE_ORDER.indexOf(b.type);
            });
        });

        return list.filter(function (courier) {
            return courier.options.length > 0;
        });
    }

    function optionPrice(option) {
        return option.method.available && option.method.price ? option.method.price : null;
    }

    function priceText(option) {
        var price = optionPrice(option);

        if (price === null) {
            return t('ck_unavailable');
        }

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

    function clearFieldError(field) {
        var holder = root.querySelector('[data-sf-error-for="' + field + '"]');

        if (holder) {
            holder.hidden = true;
            holder.textContent = '';
        }
    }

    function afterChoice() {
        toggleDestination();
        renderTotals();
        refreshPlaceOrder();
    }

    function selectOption(courier, option) {
        var price = optionPrice(option);

        if (price === null) {
            return;
        }

        // carrier_code is the chosen courier; a method without one is named by itself (the API requires the field).
        selection = {
            id: option.id,
            handle: option.method.handle,
            priceMinor: price.minor,
            currency: price.currency,
            kind: option.kind,
            carrier: courier.name === null ? option.method.name : courier.name,
        };
        preferredCourier = courier.key;
        preferredMethod = option.id;
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

    function renderTypes() {
        clear(typeList);

        var courier = chosenCourier === null ? null : findCourier(chosenCourier);

        // Nothing to ask when no courier is chosen, or the courier offers ONE type (its row already shows it and the price).
        if (courier === null || courier.options.length < 2) {
            typeBox.hidden = true;

            return;
        }

        courier.options.forEach(function (option) {
            var available = optionPrice(option) !== null;

            typeList.appendChild(radioRow('delivery_option', option.id, selection !== null && selection.id === option.id, ! available, typeLabel(option.type) + ' — ' + priceText(option), function () {
                selectOption(courier, option);
                afterChoice();
            }));
        });

        typeBox.hidden = false;
    }

    function renderCouriers() {
        clear(courierList);

        couriers.forEach(function (courier) {
            var single = courier.options.length === 1 ? courier.options[0] : null;
            var usable = courier.options.some(function (option) { return optionPrice(option) !== null; });
            var text = courierTitle(courier);

            // A courier with ONE delivery type skips the second choice: that type and its price are on the courier's own row.
            if (single !== null) {
                text += ' — ' + typeLabel(single.type) + ' — ' + priceText(single);
            }

            courierList.appendChild(radioRow('courier', courier.key, chosenCourier === courier.key, ! usable, text, function () {
                chosenCourier = courier.key;
                preferredCourier = courier.key;
                preferredMethod = null;
                selection = null;

                if (single !== null) {
                    selectOption(courier, single);
                }

                renderTypes();
                afterChoice();
            }));
        });

        courierBox.hidden = couriers.length === 0;
    }

    function renderQuote(streetData, pickupData) {
        var previousCourier = preferredCourier;
        var previousMethod = preferredMethod;
        var hints = [];

        quote = streetData || pickupData;
        quotePending = false;
        selection = null;
        chosenCourier = null;
        couriers = buildCouriers(streetData, pickupData);

        [streetData, pickupData].forEach(function (data) {
            var hint = data ? data.free_shipping_hint : null;

            if (hint && typeof hint.text === 'string' && hint.text !== '' && hints.indexOf(hint.text) === -1) {
                hints.push(hint.text);
            }
        });

        hintLine.textContent = hints.join(' ');
        hintLine.hidden = hints.length === 0;

        // A choice made before is kept when this quote still offers it (re-captured with the NEW handle and price) and is
        // cleared, with a short message, when it does not.
        var gone = false;
        var courier = previousCourier === null ? null : findCourier(previousCourier);

        if (previousCourier !== null && courier === null) {
            gone = true;
            preferredCourier = null;
            preferredMethod = null;
        } else if (courier !== null) {
            chosenCourier = courier.key;

            if (previousMethod !== null) {
                var kept = courier.options.filter(function (option) {
                    return option.id === previousMethod && optionPrice(option) !== null;
                })[0];

                if (kept) {
                    selectOption(courier, kept);
                } else {
                    gone = true;
                    preferredMethod = null;
                }
            }
        }

        renderCouriers();
        renderTypes();

        if (couriers.length === 0) {
            message(quoteStatus, t('ck_no_methods'));
        } else if (gone) {
            message(quoteStatus, t('ck_choice_gone'));
        } else {
            message(quoteStatus, selection !== null ? '' : (chosenCourier === null ? t('ck_choose_courier') : t('ck_choose_type')));
        }

        afterChoice();
    }

    // ------------------------------------------------------------------------------------------ submit

    function payload() {
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

        if (deliveryType() === 'street_address') {
            body.city = fieldValue('city');

            STREET_FIELDS.forEach(function (name) {
                if (fieldValue(name) !== '') {
                    body[name] = fieldValue(name);
                }
            });
        } else {
            // The place the pick-up point was quoted for is the point's settlement; the courier is the one the customer chose.
            body.settlement = fieldValue('city');
            body.carrier_code = selection.carrier;

            PICKUP_FIELDS.forEach(function (name) {
                if (fieldValue(name) !== '') {
                    body[name] = fieldValue(name);
                }
            });
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

    async function submit(event) {
        event.preventDefault();

        if (submitting || cart === null) {
            return;
        }

        clearErrors();
        message(statusLine, '');

        if (selection === null) {
            showSummary([{ field: null, text: couriers.length > 0 && chosenCourier === null ? t('ck_choose_courier') : t('ck_choose_method') }]);

            return;
        }

        if (! form.checkValidity()) {
            form.reportValidity();

            return;
        }

        submitting = true;
        refreshPlaceOrder();
        message(statusLine, t('ck_placing'));

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

                message(statusLine, '');
                showSummary([{ field: null, text: t('generic_error') }]);
            } else if (result.status === 422 && result.data && result.data.errors && typeof result.data.errors === 'object') {
                message(statusLine, '');
                showSummary(fieldErrors(result.data.errors));
            } else if (result.status === 422 || result.status === 409 || result.status === 404) {
                message(statusLine, '');
                var reason = result.data && result.data.reason ? result.data.reason : null;
                var text = apiMessage(result);

                if (reason === 'shipping_price_changed' && result.data.price) {
                    text += ' ' + t('ck_new_price', { price: formatMoney(result.data.price) });
                }

                showSummary([{ field: null, text: text }]);

                if (STALE_QUOTE_REASONS.indexOf(reason) !== -1) {
                    // The customer chooses again from the fresh quote: nothing is kept.
                    selection = null;
                    preferredCourier = null;
                    preferredMethod = null;
                    scheduleQuote(true);
                } else if (result.status !== 422 || reason === 'empty_cart') {
                    // Stock, price or cart changed: show the cart as it is now (an empty one sends the visitor to /cart).
                    await loadCart();
                    message(statusLine, t('ck_cart_changed'));
                }
            } else {
                message(statusLine, '');
                showSummary([{ field: null, text: t('generic_error') }]);
            }
        } catch (e) {
            message(statusLine, '');
            showSummary([{ field: null, text: t('generic_error') }]);
        }

        if (! keepLocked) {
            submitting = false;
            refreshPlaceOrder();
        }
    }

    // --------------------------------------------------------------------------------------------- wiring

    // The destination moved: ask again after a pause. The choice stays on screen (and is re-captured with the new handle when
    // the new quote still offers it) so the fields the customer is typing in never disappear under the cursor; the button
    // stays locked until the new answer is in.
    ['country', 'city', 'postal_code'].forEach(function (name) {
        form.elements[name].addEventListener('input', function () {
            scheduleQuote(false);
        });
    });

    // The mobile bar: the same summary, opened in place.
    summaryToggle.addEventListener('click', function () {
        var open = summaryToggle.getAttribute('aria-expanded') !== 'true';

        summaryToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        summaryPanel.setAttribute('data-open', open ? 'true' : 'false');
        summaryToggleLabel.textContent = open ? t('ck_summary_hide') : t('ck_summary_show');
    });

    form.addEventListener('submit', submit);
    toggleDestination();

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
