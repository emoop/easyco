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
// ONE field: the customer types the office's address, name or code, whatever they know (checkout form v2, D3).
var PICKUP_FIELDS = ['pickup_point_address'];
var STALE_QUOTE_REASONS = ['shipping_price_changed', 'shipping_quote_expired', 'shipping_method_unavailable'];
var KIND_ORDER = ['address', 'office', 'locker'];
var REGION_MODES = ['optional', 'required', 'hidden'];
var NOTE_MAX = 1000;
var NOTE_WARN_AT = 0.9;
// A field the API names that has no input of its own is shown where the customer can act on it.
var FIELD_ALIAS = { settlement: 'city', carrier_code: 'shipping_method_id', pickup_point_kind: 'shipping_method_id' };

// The invoice block (order-context-design.md 9.3, stage C2-A): its inputs are named `invoice_*`; the API names the same fields `invoice.*`.
var INVOICE_REQUIRED_ALWAYS = ['invoice_legal_name', 'invoice_country', 'invoice_city', 'invoice_postal_code', 'invoice_address_line_1'];
var INVOICE_API_ALIAS = { 'invoice.company_registration_number': 'invoice_registration_number' };

/** The input that carries an API field: `invoice.tax_id` is `invoice_tax_id`; every other field is its own name. */
function inputNameFor(apiName) {
    if (Object.prototype.hasOwnProperty.call(INVOICE_API_ALIAS, apiName)) {
        return INVOICE_API_ALIAS[apiName];
    }

    return apiName.indexOf('invoice.') === 0 ? 'invoice_' + apiName.slice('invoice.'.length) : apiName;
}

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

/** The visible asterisk of a label: the same markup the server renders for an always-required field. */
function markerNode() {
    var marker = el('span', 'sf-required', '*');

    marker.setAttribute('aria-hidden', 'true');

    return marker;
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
    var postalBox = root.querySelector('[data-sf-postal-field]');     // the postal code next to the place: shown for a street address and before a kind is chosen
    var pickupBox = root.querySelector('[data-sf-pickup-fields]');
    var regionBox = root.querySelector('[data-sf-region-field]');
    var noteCounter = root.querySelector('[data-sf-note-counter]');
    var promoHost = root.querySelector('[data-sf-promo]');
    var termsBox = form.elements.terms_accepted || null;          // the terms checkbox exists only when the merchant asks for it
    var invoiceToggle = form.elements.invoice_requested || null;  // the invoice box exists only when the merchant offers invoices
    var invoiceRoot = root.querySelector('[data-sf-invoice]');
    var invoiceBox = root.querySelector('[data-sf-invoice-block]');
    var invoiceCompanyBox = root.querySelector('[data-sf-invoice-company]');
    var invoiceCopy = root.querySelector('[data-sf-invoice-copy]');
    var defaultOrderLabel = placeOrder.textContent;               // the button's own label; a payment method may replace it while chosen
    var layout = root.querySelector('[data-sf-checkout-layout]');
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

    var invoiceRules = {};     // ISO code => the merchant's rule for it (labels and requirements; never a format)
    var invoiceCountryTouched = false; // the customer chose the invoice country (or copied the delivery address): it stops following the delivery country

    try {
        var rawInvoiceRules = invoiceRoot ? invoiceRoot.getAttribute('data-invoice-rules') : null;

        if (rawInvoiceRules) {
            invoiceRules = JSON.parse(rawInvoiceRules);
        }
    } catch (e) {
        // an unreadable rule set is "no rules": the generic rule applies and the server stays the judge
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
    var termsStale = false;    // the terms changed while the form was filled: the page must be reloaded to show the new version
    var paymentLocked = false; // every payment method was refused for this customer (a block, cod-protection-design.md 4): nothing can be ordered here
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

    /**
     * The requirement of a field that CHANGES on this page (the street line by kind, the pick-up field by kind, the region by country):
     * `required`, `aria-required` and the label's asterisk all follow ONE state, so the marker can never disagree with the rule
     * (checkout form v2, section 4). The always-required fields carry their marker in the server-rendered markup.
     */
    function setRequired(name, on) {
        var input = form.elements[name];
        var label = input && input.id ? root.querySelector('label[for="' + input.id + '"]') : null;
        var marker = label ? label.querySelector('[data-sf-marker]') : null;

        input.required = on;

        if (on) {
            input.setAttribute('aria-required', 'true');
        } else {
            input.removeAttribute('aria-required');
        }

        if (on && label && ! marker) {
            marker = markerNode();
            marker.setAttribute('data-sf-marker', 'true');
            label.appendChild(marker);
        } else if (! on && marker) {
            label.removeChild(marker);
        }
    }

    function pointFieldsFilled() {
        return PICKUP_FIELDS.every(function (name) {
            return fieldValue(name) !== '';
        });
    }

    /** The payment method that is chosen, or null (none is, until the customer picks one after a refusal). */
    function chosenPayment() {
        return form.querySelector('input[name="payment_method"]:checked');
    }

    /**
     * The API refused the chosen method for this customer: `payment_method_blocked` (cod-protection-design.md 4). The answer is deliberately
     * neutral and so is the page: every BLOCKABLE method (cash on delivery) is marked unavailable and unchosen, and the customer is told to choose
     * another; when no other method remains the order button is locked and she is told to contact the shop. Never "blocked", never which identifier.
     * There is no pre-check: this happens only as the answer to a real attempt.
     */
    function applyBlockedPayment() {
        var remaining = 0;

        form.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
            if (radio.getAttribute('data-blockable') === '1') {
                radio.checked = false;
                radio.disabled = true;
                radio.setAttribute('aria-disabled', 'true');
                radio.setAttribute('data-state', 'unavailable');
            } else if (! radio.disabled) {
                remaining += 1;
            }
        });

        var text = remaining === 0 ? t('ck_contact_shop') : t('ck_payment_unavailable');
        var holder = root.querySelector('[data-sf-error-for="payment_method"]');

        paymentLocked = remaining === 0;
        holder.textContent = text;
        holder.hidden = false;
        refreshOrderLabel();
        showSummary([{ field: null, text: text }]);
    }

    function refreshOrderLabel() {
        var chosen = form.querySelector('input[name="payment_method"]:checked');
        var label = chosen ? chosen.getAttribute('data-place-order-label') : null;

        placeOrder.textContent = label ? label : defaultOrderLabel;
    }

    function refreshPlaceOrder() {
        // Never while a quote is unfinished, before a FINAL (non-preview) price is chosen, or while a pick-up point is half typed.
        placeOrder.disabled = submitting || promoBusy || termsStale || paymentLocked || chosenPayment() === null || cart === null || selection === null || quotePending || ! selection.final || (isPickupKind(selection.kind) && ! pointFieldsFilled());
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

            // The field's own hint (the pick-up help line, the note counter) stays linked; only the error id goes.
            if (input.getAttribute('data-sf-describedby')) {
                input.setAttribute('aria-describedby', input.getAttribute('data-sf-describedby'));
            } else {
                input.removeAttribute('aria-describedby');
            }
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
            var field = FIELD_ALIAS[name] || inputNameFor(name);
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
                input.setAttribute('aria-describedby', holder.id + (input.getAttribute('data-sf-describedby') ? ' ' + input.getAttribute('data-sf-describedby') : ''));
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
        scheduleSummaryFit();
    }

    // ------------------------------------------------------------------------------------------ the sticky summary

    var fitTimer = null;

    /** Debounced: measure and place the summary again (a resize, or a change of its content). */
    function scheduleSummaryFit() {
        window.clearTimeout(fitTimer);
        fitTimer = window.setTimeout(fitSummary, 120);
    }

    /**
     * Desktop only (>= 1024px). The summary is `position: sticky`; its top is `--sf-summary-top`: the header's height + 8px, or, when the
     * summary starts lower than that, its own natural top minus 24px, so it moves at most ~24px before it sticks and its top is never
     * covered by the sticky header. A summary taller than the space below that top gets a scrolling ITEM LIST (max-height), while the
     * promo box and the totals stay in view. The mobile panel is not touched.
     */
    function fitSummary() {
        if (typeof summaryPanel.getBoundingClientRect !== 'function' || ! layout || typeof layout.getBoundingClientRect !== 'function') {
            return;
        }

        if (window.innerWidth < 1024) {
            summaryPanel.style.removeProperty('--sf-summary-top');
            summaryLines.style.removeProperty('max-height');
            summaryLines.style.removeProperty('overflow-y');

            return;
        }

        var header = document.querySelector('header');
        var headerHeight = header && typeof header.getBoundingClientRect === 'function' ? header.getBoundingClientRect().height : 0;
        var naturalTop = layout.getBoundingClientRect().top + (window.pageYOffset || 0);
        var top = Math.max(headerHeight + 8, naturalTop - 24);

        summaryPanel.style.setProperty('--sf-summary-top', Math.round(top) + 'px');

        // Is the summary taller than the viewport below its top? Then only the item list scrolls.
        summaryLines.style.removeProperty('max-height');
        summaryLines.style.removeProperty('overflow-y');

        var available = window.innerHeight - top - 16;
        var panelHeight = summaryPanel.getBoundingClientRect().height;
        var linesHeight = summaryLines.getBoundingClientRect().height;

        if (panelHeight > available && linesHeight > 0) {
            summaryLines.style.setProperty('max-height', Math.max(120, Math.floor(linesHeight - (panelHeight - available))) + 'px');
            summaryLines.style.setProperty('overflow-y', 'auto');
        }
    }

    // ------------------------------------------------------------------------------------------- promo code

    /** The cart page's promo box (ONE implementation, storefront-cart.js), in the summary: it only ever fills its own host, never the form. */
    function renderPromo() {
        clear(promoHost);
        promoHost.hidden = cart === null;

        if (cart !== null) {
            promoHost.appendChild(promotionBox(cart, runPromo, { compact: true }));

            // The floating label (CSS only) needs a one-space placeholder; the label itself is the promo text, shown inside the field.
            var promoInput = promoHost.querySelector('input');

            if (promoInput) {
                promoInput.setAttribute('placeholder', ' ');
            }
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
        setRequired('region', street && mode === 'required');

        if (mode === 'hidden') {
            input.value = '';
            clearFieldError('region');
        }
    }

    // --------------------------------------------------------------------------------------------------------------- invoice

    function invoiceOn() {
        return invoiceToggle !== null && invoiceToggle.checked === true;
    }

    function invoiceType() {
        var chosen = form.querySelector('input[name="invoice_recipient_type"]:checked');

        return chosen ? chosen.value : 'company';
    }

    /** The merchant's rule for the INVOICE country, or null (the generic rule: a company's tax id is required, nothing else). */
    function invoiceRule() {
        var code = normalizeCountry(fieldValue('invoice_country'));
        var rule = Object.prototype.hasOwnProperty.call(invoiceRules, code) ? invoiceRules[code] : null;

        return rule && typeof rule === 'object' ? rule : null;
    }

    function labelHolder(name) {
        var input = form.elements[name];
        var label = input && input.id ? root.querySelector('label[for="' + input.id + '"]') : null;

        return label ? label.querySelector('[data-sf-label-text]') : null;
    }

    /** The visible words of a label (its asterisk is the marker, kept apart): textContent only, never markup. */
    function setLabelText(name, text) {
        var holder = labelHolder(name);

        if (holder) {
            holder.textContent = text;
        }
    }

    /**
     * The WHOLE invoice block follows ONE state: the box. Unticked: the block is hidden and NOTHING in it is required (so the browser's own
     * check passes with every invoice field empty) and nothing in it is sent. Ticked: it is shown, the always-required fields are marked,
     * and what a company must give follows the rule of the invoice country. Typed values stay in the inputs while it is hidden.
     */
    function applyInvoice() {
        if (invoiceToggle === null) {
            return;
        }

        var on = invoiceOn();
        var company = invoiceType() === 'company';
        var rule = invoiceRule();
        var nameHolder = labelHolder('invoice_legal_name');

        invoiceBox.hidden = ! on;
        invoiceCompanyBox.hidden = ! on || ! company;
        invoiceCopy.hidden = ! on || deliveryKind() !== 'address';

        INVOICE_REQUIRED_ALWAYS.forEach(function (name) {
            setRequired(name, on);
        });

        setRequired('invoice_tax_id', on && company && (rule === null || rule.tax_id_required_for_company !== false));
        setRequired('invoice_registration_number', on && company && rule !== null && rule.registration_number_required === true);

        if (nameHolder) {
            nameHolder.textContent = nameHolder.getAttribute(company ? 'data-label-company' : 'data-label-individual') || nameHolder.textContent;
        }

        [['invoice_tax_id', 'tax_id_label'], ['invoice_registration_number', 'registration_number_label'], ['invoice_representative', 'representative_label']].forEach(function (pair) {
            var holder = labelHolder(pair[0]);
            var configured = rule !== null && typeof rule[pair[1]] === 'string' && rule[pair[1]] !== '' ? rule[pair[1]] : null;

            if (holder) {
                setLabelText(pair[0], configured !== null ? configured : holder.getAttribute('data-label-default'));
            }
        });

        // The invoice email is optional: its placeholder says what is used when it is left empty.
        form.elements.invoice_email.setAttribute('placeholder', fieldValue('email'));
    }

    /** What the customer typed, as the API's `invoice` object. An individual never sends a tax id, a registration number or a representative. */
    function invoicePayload() {
        var company = invoiceType() === 'company';
        var invoice = {
            recipient_type: invoiceType(),
            legal_name: fieldValue('invoice_legal_name'),
            country: fieldValue('invoice_country'),
            city: fieldValue('invoice_city'),
            postal_code: fieldValue('invoice_postal_code'),
            address_line_1: fieldValue('invoice_address_line_1'),
        };

        if (fieldValue('invoice_address_line_2') !== '') {
            invoice.address_line_2 = fieldValue('invoice_address_line_2');
        }

        if (fieldValue('invoice_email') !== '') {
            invoice.email = fieldValue('invoice_email');
        }

        if (company) {
            [['tax_id', 'invoice_tax_id'], ['company_registration_number', 'invoice_registration_number'], ['representative', 'invoice_representative']].forEach(function (pair) {
                if (fieldValue(pair[1]) !== '') {
                    invoice[pair[0]] = fieldValue(pair[1]);
                }
            });
        }

        return invoice;
    }

    /** The invoice country follows the delivery country until the customer chooses one herself. */
    function followDeliveryCountry() {
        if (invoiceToggle === null || invoiceCountryTouched) {
            return;
        }

        var country = fieldValue('country');
        var known = Array.prototype.some.call(form.elements.invoice_country.options || [], function (option) {
            return option.value === country;
        });

        if (known) {
            form.elements.invoice_country.value = country;
        }

        applyInvoice();
    }

    /** "Same as the delivery address": copies the STREET delivery fields into the invoice address (the button exists only for a street delivery). */
    function copyDeliveryToInvoice() {
        var country = fieldValue('country');
        var known = Array.prototype.some.call(form.elements.invoice_country.options || [], function (option) {
            return option.value === country;
        });

        if (known) {
            form.elements.invoice_country.value = country;
        }

        form.elements.invoice_city.value = fieldValue('city');
        form.elements.invoice_postal_code.value = fieldValue('postal_code');
        form.elements.invoice_address_line_1.value = fieldValue('address_line_1');
        form.elements.invoice_address_line_2.value = fieldValue('address_line_2');
        invoiceCountryTouched = true;
        applyInvoice();
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
        postalBox.hidden = pickup;
        pickupBox.hidden = ! pickup;

        setRequired('address_line_1', street);
        PICKUP_FIELDS.forEach(function (name) {
            setRequired(name, pickup);
        });

        applyRegionMode();
        applyInvoice();
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
            recipient_first_name: fieldValue('recipient_first_name'),
            recipient_last_name: fieldValue('recipient_last_name'),
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

        // The optional choices and the consent (checkout form v2, section 6): ONLY when the box is on the page, i.e. when the merchant shows
        // it. An unticked box is sent as false ("offered, not ticked"); nothing is ever pre-ticked. The terms carry the version the page
        // displayed so the server can refuse a stale one.
        ['call_before_shipping', 'marketing_consent'].forEach(function (name) {
            if (form.elements[name]) {
                body[name] = form.elements[name].checked === true;
            }
        });

        if (termsBox) {
            body.terms_accepted = true;
            body.terms_version = termsBox.getAttribute('data-sf-terms-version');
        }

        // The invoice: ONLY while the box is ticked. Unticked, there is no `invoice` key at all, whatever is typed in the hidden block.
        if (invoiceOn()) {
            body.invoice = invoicePayload();
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

        if (submitting || cart === null || paymentLocked || chosenPayment() === null) {
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

        if (termsBox && termsBox.checked !== true) {
            var termsHolder = root.querySelector('[data-sf-error-for="terms_accepted"]');
            var termsText = t('ck_terms_required');

            termsHolder.textContent = termsText;
            termsHolder.hidden = false;
            termsBox.setAttribute('aria-invalid', 'true');
            termsBox.setAttribute('aria-describedby', termsHolder.id);
            showSummary([{ field: 'terms_accepted', text: termsText }]);

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
                } else if (reason === 'payment_method_blocked') {
                    // A block on the IP, the phone or the email: the method is marked unavailable and nothing is resubmitted (see applyBlockedPayment).
                    applyBlockedPayment();
                } else if (reason === 'terms_version_changed') {
                    // The merchant edited the terms while this form was open: the checkbox is cleared and the order is locked until the page is
                    // reloaded (the page shows the NEW version); nothing is ever resubmitted by itself.
                    showSummary([{ field: null, text: apiMessage(result) + ' ' + t('ck_terms_reload') }]);
                    termsBox.checked = false;
                    termsStale = true;
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
        var onMove = function () {
            if (name === 'country') {
                applyRegionMode();
            }

            scheduleQuote(false);
        };

        form.elements[name].addEventListener('input', onMove);

        if (name === 'country') {
            form.elements[name].addEventListener('change', followDeliveryCountry);
        }

        // The country is a native <select>: it announces a choice with `change`.
        if (name === 'country') {
            form.elements[name].addEventListener('change', onMove);
        }
    });

    // The order button's label follows the chosen payment method when its descriptor has its own (it must keep the obligation wording).
    form.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            refreshOrderLabel();
            clearFieldError('payment_method');
            refreshPlaceOrder();
        });
    });

    PICKUP_FIELDS.forEach(function (name) {
        form.elements[name].addEventListener('input', refreshPlaceOrder);
    });

    kindSelect.addEventListener('change', function () {
        chooseKind(kindSelect.value);
    });

    form.elements.customer_note.addEventListener('input', updateNoteCounter);

    // The invoice: the box shows and hides the block; the type, the invoice country and the order email re-apply what depends on them.
    if (invoiceToggle !== null) {
        invoiceToggle.addEventListener('change', applyInvoice);
        form.querySelectorAll('input[name="invoice_recipient_type"]').forEach(function (radio) {
            radio.addEventListener('change', applyInvoice);
        });
        form.elements.invoice_country.addEventListener('change', function () {
            invoiceCountryTouched = true;
            applyInvoice();
        });
        form.elements.email.addEventListener('input', applyInvoice);
        invoiceCopy.addEventListener('click', copyDeliveryToInvoice);
        applyInvoice();
    }

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
    refreshOrderLabel();

    if (typeof window.addEventListener === 'function') {
        window.addEventListener('resize', scheduleSummaryFit);
    }

    scheduleSummaryFit();

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
