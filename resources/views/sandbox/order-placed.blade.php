@extends('sandbox.layout')

@section('title', 'Order placed')

@section('content')
    <h1>Order placed</h1>

    {{-- D6: nothing on this page is fetched from the server. The order below comes
         from this browser's own sessionStorage, written by the checkout page from
         the 201 response. There is no order id in the URL and no endpoint that
         would read one, so this page can only ever show the order this browser
         just placed. --}}
    <div class="notice" id="order-missing" hidden>
        Nothing to show here. This page displays the order <em>this browser</em> placed — it is not a lookup by order
        id, and reloading after clearing site data shows nothing. Place an order through
        <a href="{{ route('sandbox.checkout') }}">checkout</a> to see this page populated.
    </div>

    <p class="message ok" id="order-placed-message" hidden></p>
    <div id="order-body" hidden>
        <h2 id="order-heading"></h2>
        <p class="muted" id="order-meta"></p>
        <p class="muted" id="order-shipping" hidden></p>
        <p class="muted" id="order-office" hidden></p>
        <p class="message ok" id="order-already" hidden>This order had already been placed when you submitted again (idempotent replay).</p>

        <table>
            <thead>
                <tr><th>Product</th><th>Attributes</th><th>SKU</th><th>Quantity</th><th>Final unit price</th><th>Promotion discount</th><th>Net paid</th></tr>
            </thead>
            <tbody id="order-lines"></tbody>
        </table>

        <div class="totals">
            <div>Subtotal: <strong id="order-subtotal">—</strong></div>
            <div>Discount: <strong id="order-discount">—</strong></div>
            <div>Delivery: <strong id="order-delivery">—</strong></div>
            <div>Total: <strong id="order-total">—</strong></div>
            <div>Payment: <strong id="order-payment">—</strong></div>
        </div>

        <div class="actions">
            <a href="{{ route('sandbox.index') }}">Back to the catalog</a>
            <button class="link" type="button" id="forget-order">forget this order</button>
        </div>
    </div>
@endsection

<script>
document.addEventListener('DOMContentLoaded', function () {
    var api = window.sandboxApi;
    var stored = api.readOrder();

    if (stored === null || !stored.order) {
        document.getElementById('order-missing').hidden = false;

        return;
    }

    var order = stored.order;
    var payment = stored.payment;
    // The shipping facts of the 201 response (stage 4e). An order stored before
    // shipping existed has none — the page must still render.
    var shipping = stored.shipping;
    var lines = order.lines || [];

    document.getElementById('order-heading').textContent = 'Order ' + (order.id === null ? '(id pending)' : order.id);
    document.getElementById('order-meta').textContent =
        order.status + ' · ' + order.email + ' · ' + order.recipient_name + ' · ' + order.delivery_type +
        (order.applied_promotion_code ? ' · promotion ' + order.applied_promotion_code : '');
    document.getElementById('order-already').hidden = stored.already_placed !== true;

    var shippingLine = document.getElementById('order-shipping');
    var officeLine = document.getElementById('order-office');
    var deliveryTotal = document.getElementById('order-delivery');

    if (shipping === null || shipping === undefined) {
        shippingLine.hidden = true;
        officeLine.hidden = true;
        deliveryTotal.textContent = '—';
    } else {
        var shipMoney = { minor: shipping.amount_minor, currency: shipping.currency };

        shippingLine.textContent = 'Delivery: ' + shipping.method_name +
            ' (' + (shipping.courier || '-') + ', ' + (shipping.delivery_type || '-') + ') - ' + api.money(shipMoney);
        shippingLine.hidden = false;
        deliveryTotal.textContent = api.money(shipMoney);

        // A pickup order carries the office the customer chose, as they saw it.
        if (order.pickup_point_name && order.pickup_point_address) {
            officeLine.textContent = 'Office: ' + order.pickup_point_name + ', ' + order.pickup_point_address;
            officeLine.hidden = false;
        } else {
            officeLine.hidden = true;
        }
    }

    var body = document.getElementById('order-lines');
    lines.forEach(function (line) {
        var row = document.createElement('tr');
        [line.product_name || '—',
            api.attributes(line.attributes) || '—',
            line.sku || '—',
            String(line.quantity),
            api.money(line.final_unit_price),
            api.money(line.promotion_discount_share),
            api.money(line.net_paid_amount)].forEach(function (text) {
            var td = document.createElement('td');
            td.textContent = text;
            row.appendChild(td);
        });
        body.appendChild(row);
    });

    document.getElementById('order-subtotal').textContent = api.money(order.subtotal);
    document.getElementById('order-discount').textContent = api.money(order.discount_amount);
    document.getElementById('order-total').textContent = api.money(order.total);
    document.getElementById('order-payment').textContent = payment === null || payment === undefined
        ? 'no payment recorded'
        : payment.method + ' (' + payment.status + ') ' + api.money(payment.amount);

    document.getElementById('order-body').hidden = false;

    document.getElementById('forget-order').addEventListener('click', function () {
        sessionStorage.removeItem('sandbox.lastOrder');
        window.location.reload();
    });
});
</script>
