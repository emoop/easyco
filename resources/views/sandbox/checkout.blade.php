@extends('sandbox.layout')

@section('title', 'Checkout')

@section('content')
    <h1>Checkout</h1>
    <p class="muted">
        This form posts to the real <code>POST /api/checkout</code> with the customer's own session cookie and CSRF
        token. The payment methods below are the ones the application actually resolves — nothing here is hardcoded.
    </p>

    <div class="notice" id="checkout-summary">Loading your cart…</div>
    <p class="message error" id="checkout-error" hidden></p>

    <form id="checkout-form">
        <h2>Contact</h2>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required></div>
        <div class="field"><label for="recipient_name">Recipient name</label><input id="recipient_name" name="recipient_name" type="text" required></div>
        <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" type="text" required></div>

        <h2>Payment</h2>
        <div class="field">
            @foreach ($paymentMethods as $method)
                <label>
                    <input type="radio" name="payment_method" value="{{ $method }}" data-payment-method="{{ $method }}" @checked($loop->first)>
                    {{ ucfirst(str_replace('_', ' ', $method)) }}
                </label>
            @endforeach
        </div>

        <h2>Delivery</h2>
        <div class="field">
            <label><input type="radio" name="destination_kind" value="address" checked> Address</label>
            <label><input type="radio" name="destination_kind" value="office"> Office</label>
            <label><input type="radio" name="destination_kind" value="locker"> Locker</label>
        </div>

        {{-- The delivery country belongs to BOTH delivery types (owner decision D1): always sent. --}}
        <div class="field"><label for="country">Country</label><input id="country" name="country" type="text" value="BG"></div>

        <div id="street-address">
            <div class="field"><label for="city">City</label><input id="city" name="city" type="text"></div>
            <div class="field"><label for="address_line_1">Address</label><input id="address_line_1" name="address_line_1" type="text"></div>
            <div class="field"><label for="address_line_2">Address (line 2)</label><input id="address_line_2" name="address_line_2" type="text"></div>
            <div class="field"><label for="postal_code">Postal code</label><input id="postal_code" name="postal_code" type="text"></div>
        </div>

        <div id="pickup-point" hidden>
            <p class="muted">This sandbox has no courier map, so type the pickup point's name and address yourself.</p>
            <div class="field"><label for="carrier_code">Carrier</label><input id="carrier_code" name="carrier_code" type="text"></div>
            <div class="field"><label for="pickup_point_reference">Pickup point</label><input id="pickup_point_reference" name="pickup_point_reference" type="text"></div>
            <div class="field"><label for="pickup_point_name">Pickup point name</label><input id="pickup_point_name" name="pickup_point_name" type="text"></div>
            <div class="field"><label for="pickup_point_address">Pickup point address</label><input id="pickup_point_address" name="pickup_point_address" type="text"></div>
            <div class="field"><label for="settlement">Settlement</label><input id="settlement" name="settlement" type="text"></div>
        </div>

        <h2>Delivery method</h2>
        <p class="muted">Delivery options and their prices come from the real <code>POST /api/shipping/quote</code> — nothing here is hardcoded.</p>
        <button class="primary" type="button" id="quote-button">Show delivery options</button>
        <p class="message" id="quote-status"></p>
        <div id="shipping-options"></div>
        <div class="totals" id="order-totals"></div>

        <div class="actions">
            <button class="primary" type="submit" id="place-order">Place order</button>
            <a href="{{ route('sandbox.cart') }}">Back to cart</a>
        </div>
    </form>
@endsection

<script>
document.addEventListener('DOMContentLoaded', function () {
    var api = window.sandboxApi;
    var form = document.getElementById('checkout-form');
    var error = document.getElementById('checkout-error');
    var summary = document.getElementById('checkout-summary');
    var street = document.getElementById('street-address');
    var pickup = document.getElementById('pickup-point');
    var streetFields = ['city', 'address_line_1', 'address_line_2', 'postal_code'];
    var pickupFields = ['carrier_code', 'pickup_point_reference', 'pickup_point_name', 'pickup_point_address', 'settlement'];
    var placeOrder = document.getElementById('place-order');
    var quoteButton = document.getElementById('quote-button');
    var quoteStatus = document.getElementById('quote-status');
    var options = document.getElementById('shipping-options');
    var totals = document.getElementById('order-totals');
    var deliveryLabels = { address: 'Address', office: 'Office', locker: 'Locker', other: 'Other' };

    // The ONE piece of state the delivery step keeps: the method the customer chose.
    // Empty until they pick one — no method is ever preselected.
    var selection = { methodId: null, handle: null, priceMinor: null, currency: null };
    var quoteGoods = null;    // goods_after_discount from the last quote
    var quoteShown = false;   // whether the options on screen came from a quote
    var cartReady = false;    // whether the cart has something to check out
    var submitting = false;   // a request is running — never double-submit

    function fail(text) { error.textContent = text; error.hidden = false; }

    // The customer's mental model: one of Address / Office / Locker.
    function destinationKind() { return form.querySelector('input[name="destination_kind"]:checked').value; }

    // The backend's model: a street address, or a pickup point (office or locker).
    function deliveryType() { return destinationKind() === 'address' ? 'street_address' : 'pickup_point'; }

    function deliveryLabel(code) { return deliveryLabels[code] || code; }

    // Place order stays disabled until the cart is checkable AND a delivery method
    // is chosen, and while a request is running.
    function refreshPlaceOrder() {
        placeOrder.disabled = submitting || !cartReady || selection.methodId === null;
    }

    function toggleDelivery() {
        var isStreet = deliveryType() === 'street_address';
        street.hidden = !isStreet;
        pickup.hidden = isStreet;
    }

    // Drop the stale options the moment the destination changes after a quote.
    function invalidate() {
        quoteShown = false;
        quoteGoods = null;
        selection = { methodId: null, handle: null, priceMinor: null, currency: null };
        options.textContent = '';
        totals.textContent = '';
        refreshPlaceOrder();
        quoteStatus.className = 'message';
        quoteStatus.textContent = 'Delivery options changed - show them again';
    }

    form.querySelectorAll('input[name="destination_kind"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            toggleDelivery();

            if (quoteShown) { invalidate(); }
        });
    });
    toggleDelivery();

    ['country', 'city', 'postal_code', 'settlement'].forEach(function (name) {
        document.getElementById(name).addEventListener('change', function () {
            if (quoteShown) { invalidate(); }
        });
    });

    /** The body POST /api/shipping/quote reads: the cart is resolved from the session, never sent. */
    function quoteBody() {
        var type = deliveryType();
        var body = {
            delivery_type: type,
            country: document.getElementById('country').value,
            // For a pickup point the settlement is the point's own settlement; for a
            // street address it is the city.
            settlement: type === 'pickup_point' ? document.getElementById('settlement').value : document.getElementById('city').value,
        };

        if (type === 'street_address') {
            var postal = document.getElementById('postal_code').value;

            if (postal !== '') { body.postal_code = postal; }
        }

        return body;
    }

    function renderMethod(method) {
        var row = document.createElement('div');
        row.className = 'field';

        var label = document.createElement('label');
        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'shipping_method';
        radio.value = method.id;

        if (method.available) {
            radio.addEventListener('change', function () {
                selection = {
                    methodId: method.id,
                    handle: method.handle,
                    priceMinor: method.price.minor,
                    currency: method.price.currency,
                };
                refreshPlaceOrder();
                renderTotals();
            });
        } else {
            // Never selectable: the API refused it for this destination.
            radio.disabled = true;
        }

        label.appendChild(radio);

        var text = method.name;

        if (method.delivery_type) { text += ' — ' + deliveryLabel(method.delivery_type); }

        if (method.available) { text += ' — ' + api.money(method.price); }

        label.appendChild(document.createTextNode(' ' + text));
        row.appendChild(label);

        if (method.available && method.remaining_to_free_minor !== null && method.remaining_to_free_minor !== undefined && method.remaining_to_free_minor > 0) {
            var remaining = document.createElement('div');
            remaining.className = 'muted';
            remaining.textContent = 'Add ' + api.money({ minor: method.remaining_to_free_minor, currency: method.price.currency }) + ' more for free delivery';
            row.appendChild(remaining);
        }

        if (!method.available && method.unavailable_reason) {
            var reason = document.createElement('div');
            reason.className = 'muted';
            reason.textContent = method.unavailable_reason;
            row.appendChild(reason);
        }

        return row;
    }

    function renderQuote(quote) {
        quoteGoods = quote.goods_after_discount;
        selection = { methodId: null, handle: null, priceMinor: null, currency: quote.currency };
        refreshPlaceOrder();

        options.textContent = '';

        var hint = quote.free_shipping_hint;

        if (hint && hint.text) {
            var hintLine = document.createElement('p');
            hintLine.className = 'muted';
            hintLine.textContent = hint.text;
            options.appendChild(hintLine);
        }

        var pickupWanted = deliveryType() === 'pickup_point';
        var byId = {};

        (quote.methods || []).forEach(function (method) { byId[method.id] = method; });

        var groups = quote.groups && quote.groups.length > 0
            ? quote.groups
            : [{ courier: null, methods: (quote.methods || []).map(function (method) { return method.id; }) }];

        var shown = 0;
        // The methods the filter removed, and the destination kinds they DO serve,
        // in the order they were offered — never hidden silently.
        var removedCount = 0;
        var removedKinds = [];

        // A generic method (no type, or the catch-all 'other') serves any destination;
        // a typed method serves only its own kind (address / office / locker).
        function servesDestination(method) {
            if (method.requires_pickup_point !== pickupWanted) { return false; }

            var type = method.delivery_type;

            if (type === null || type === undefined || type === 'other') { return true; }

            return type === (pickupWanted ? destinationKind() : 'address');
        }

        // The destination kind a removed method belongs to, or null when it cannot be
        // told (never invented).
        function kindOf(method) {
            if (method.requires_pickup_point) {
                return method.delivery_type === 'office' || method.delivery_type === 'locker' ? method.delivery_type : null;
            }

            return method.delivery_type === 'address' ? 'address' : null;
        }

        groups.forEach(function (group) {
            // Only the methods that can serve the chosen destination; a group left
            // with nothing is skipped entirely.
            var methods = (group.methods || []).map(function (id) { return byId[id]; }).filter(function (method) {
                return method && servesDestination(method);
            });

            (group.methods || []).forEach(function (id) {
                var method = byId[id];

                if (!method || servesDestination(method)) { return; }

                removedCount++;
                var kind = kindOf(method);

                if (kind !== null && removedKinds.indexOf(kind) === -1) { removedKinds.push(kind); }
            });

            if (methods.length === 0) { return; }

            shown += methods.length;

            var fieldset = document.createElement('fieldset');
            var legend = document.createElement('legend');
            legend.textContent = group.courier === null || group.courier === undefined ? 'Other' : group.courier;
            fieldset.appendChild(legend);

            methods.forEach(function (method) { fieldset.appendChild(renderMethod(method)); });
            options.appendChild(fieldset);
        });

        if (shown === 0) {
            var none = document.createElement('p');
            none.className = 'muted';
            none.textContent = 'No delivery method is available for this destination.';
            options.appendChild(none);
        }

        if (removedCount > 0) {
            var removed = document.createElement('p');
            removed.className = 'muted';
            removed.textContent = removedCount + ' other delivery option(s) are available for another destination';

            if (removedKinds.length > 0) {
                removed.textContent += ' (' + removedKinds.map(deliveryLabel).join(' / ') + ')';
            }

            removed.textContent += '.';
            options.appendChild(removed);
        }

        renderTotals();
    }

    function renderTotals() {
        totals.textContent = '';

        if (quoteGoods === null || quoteGoods === undefined) { return; }

        var line = document.createElement('div');

        if (selection.methodId === null) {
            line.textContent = 'Goods (after discount): ' + api.money(quoteGoods);
            totals.appendChild(line);

            return;
        }

        // The addition is in minor units; the formatting stays api.money's.
        var delivery = { minor: selection.priceMinor, currency: selection.currency };
        var total = { minor: quoteGoods.minor + selection.priceMinor, currency: quoteGoods.currency };

        line.textContent = 'Goods (after discount): ' + api.money(quoteGoods) +
            ' · Delivery: ' + api.money(delivery) +
            ' · Total: ' + api.money(total);
        totals.appendChild(line);
    }

    async function runQuote() {
        quoteButton.disabled = true;
        quoteStatus.className = 'message';
        quoteStatus.textContent = 'Loading delivery options…';

        try {
            var quote = await api.post('/api/shipping/quote', quoteBody());
            quoteShown = true;
            renderQuote(quote);
            quoteStatus.textContent = 'Choose a delivery method to continue.';
        } catch (failure) {
            var reason = failure.payload && failure.payload.reason ? ' (' + failure.payload.reason + ')' : '';
            quoteShown = false;
            quoteGoods = null;
            selection = { methodId: null, handle: null, priceMinor: null, currency: null };
            options.textContent = '';
            totals.textContent = '';
            refreshPlaceOrder();
            quoteStatus.className = 'message error';
            quoteStatus.textContent = failure.message + reason;
        } finally {
            quoteButton.disabled = false;
        }
    }

    quoteButton.addEventListener('click', runQuote);

    var displayedCartId = null;

    api.get('/api/cart').then(function (cart) {
        var lines = cart.lines || [];

        // The cart THIS page is confirming, named back to the API on submit —
        // REQUIRED by POST /api/checkout (cart-domain-design.md §14.2): after a
        // successful order the old cart stops being the current one, so only naming
        // it can answer a repeat submission with the same order.
        displayedCartId = cart.cart_id;
        cartReady = displayedCartId !== null && lines.length > 0;
        refreshPlaceOrder();

        summary.textContent = lines.length === 0
            ? 'Your cart is empty — add something before checking out.'
            : lines.map(function (line) {
                var attributes = api.attributes(line.attributes);

                return line.quantity + ' × ' + (line.product_name || line.variation_id) +
                    (attributes ? ' (' + attributes + ')' : '') + ' — ' + api.money(line.line_total);
            }).join(' · ') + ' · Total ' + api.money(cart.total);
    }).catch(function (failure) {
        summary.textContent = 'Could not read your cart.';
        fail(failure.message);
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (submitting) { return; }

        error.hidden = true;

        if (displayedCartId === null) {
            fail('Your cart is empty, or has already been checked out. Nothing to place.');

            return;
        }

        if (selection.methodId === null) {
            fail('Choose a delivery method before placing the order.');

            return;
        }

        var payload = {
            cart_id: displayedCartId,
            email: document.getElementById('email').value,
            recipient_name: document.getElementById('recipient_name').value,
            phone: document.getElementById('phone').value,
            payment_method: form.querySelector('input[name="payment_method"]:checked').value,
            delivery_type: deliveryType(),
            country: document.getElementById('country').value,
            // The delivery choice, exactly as the resolver reads it: the method id,
            // the handle the quote issued and the price the customer was shown.
            shipping_method_id: selection.methodId,
            quote_handle: selection.handle,
            expected_shipping_minor: selection.priceMinor,
        };

        (deliveryType() === 'street_address' ? streetFields : pickupFields).forEach(function (name) {
            var value = document.getElementById(name).value;

            if (value !== '') {
                payload[name] = value;
            }
        });

        submitting = true;
        refreshPlaceOrder();

        try {
            var response = await api.post('/api/checkout', payload);
            api.storeOrder(response.order, response.payment, response.shipping);
            window.location.href = '{{ route('sandbox.order-placed') }}';
        } catch (failure) {
            var reason = failure.payload && failure.payload.reason ? failure.payload.reason : null;

            if (reason === 'shipping_price_changed') {
                // Show the message plus the new figure the refusal carries, then
                // fetch fresh options so the customer sees the current price.
                var text = failure.message;

                if (failure.payload.price) {
                    text += ' New delivery price: ' + api.money(failure.payload.price);
                }

                fail(text);
                invalidate();
                await runQuote();
            } else if (reason === 'shipping_quote_expired' || reason === 'shipping_method_unavailable') {
                // The choice is stale: show why, then fetch fresh options.
                fail(failure.message);
                invalidate();
                await runQuote();
            } else if (reason === 'shipping_required' || reason === 'shipping_invalid' || reason === 'shipping_pickup_mismatch') {
                // The choice must be remade by the customer; no automatic re-quote.
                fail(failure.message);
            } else {
                fail(failure.message + (reason ? ' (' + reason + ')' : ''));
            }
        } finally {
            submitting = false;
            refreshPlaceOrder();
        }
    });
});
</script>
