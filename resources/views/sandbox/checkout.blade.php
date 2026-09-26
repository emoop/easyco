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
            <label><input type="radio" name="delivery_type" value="street_address" checked> Street address</label>
            <label><input type="radio" name="delivery_type" value="pickup_point"> Pickup point</label>
        </div>

        <div id="street-address">
            <div class="field"><label for="country">Country</label><input id="country" name="country" type="text" value="BG"></div>
            <div class="field"><label for="city">City</label><input id="city" name="city" type="text"></div>
            <div class="field"><label for="address_line_1">Address</label><input id="address_line_1" name="address_line_1" type="text"></div>
            <div class="field"><label for="address_line_2">Address (line 2)</label><input id="address_line_2" name="address_line_2" type="text"></div>
            <div class="field"><label for="postal_code">Postal code</label><input id="postal_code" name="postal_code" type="text"></div>
        </div>

        <div id="pickup-point" hidden>
            <div class="field"><label for="carrier_code">Carrier</label><input id="carrier_code" name="carrier_code" type="text"></div>
            <div class="field"><label for="pickup_point_reference">Pickup point</label><input id="pickup_point_reference" name="pickup_point_reference" type="text"></div>
            <div class="field"><label for="settlement">Settlement</label><input id="settlement" name="settlement" type="text"></div>
        </div>

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
    var streetFields = ['country', 'city', 'address_line_1', 'address_line_2', 'postal_code'];
    var pickupFields = ['carrier_code', 'pickup_point_reference', 'settlement'];

    function fail(text) { error.textContent = text; error.hidden = false; }

    function deliveryType() { return form.querySelector('input[name="delivery_type"]:checked').value; }

    function toggleDelivery() {
        var isStreet = deliveryType() === 'street_address';
        street.hidden = !isStreet;
        pickup.hidden = isStreet;
    }

    form.querySelectorAll('input[name="delivery_type"]').forEach(function (radio) {
        radio.addEventListener('change', toggleDelivery);
    });
    toggleDelivery();

    api.get('/api/cart').then(function (cart) {
        var lines = cart.lines || [];

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
        error.hidden = true;

        var payload = {
            email: document.getElementById('email').value,
            recipient_name: document.getElementById('recipient_name').value,
            phone: document.getElementById('phone').value,
            payment_method: form.querySelector('input[name="payment_method"]:checked').value,
            delivery_type: deliveryType(),
        };

        (deliveryType() === 'street_address' ? streetFields : pickupFields).forEach(function (name) {
            var value = document.getElementById(name).value;

            if (value !== '') {
                payload[name] = value;
            }
        });

        try {
            var response = await api.post('/api/checkout', payload);
            api.storeOrder(response.order, response.payment);
            window.location.href = '{{ route('sandbox.order-placed') }}';
        } catch (failure) {
            var reason = failure.payload && failure.payload.reason ? ' (' + failure.payload.reason + ')' : '';
            fail(failure.message + reason);
        }
    });
});
</script>
