@extends('sandbox.layout')

@section('title', 'Cart')

@section('content')
    <h1>Cart</h1>
    <p class="muted">
        This page asks <code>GET /api/cart</code> from your browser and renders exactly what it returns — line names,
        attributes, prices and the promotion state all come from the real Cart API.
    </p>

    <p class="message" id="cart-loading">Loading…</p>
    <p class="message error" id="cart-error" hidden></p>
    <p class="message ok" id="cart-message" hidden></p>

    <div id="cart-body" hidden>
        <table class="line-items">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Attributes</th>
                    <th>SKU</th>
                    <th>Unit price</th>
                    <th>Quantity</th>
                    <th>Line total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cart-lines"></tbody>
        </table>

        <p class="notice" id="cart-price-note" hidden></p>

        <div class="field" style="margin-top: 1rem">
            <label for="promotion-code">Promotion code</label>
            <input id="promotion-code" type="text" size="18" autocomplete="off">
            <button class="primary" type="button" id="apply-promotion">Apply</button>
            <button class="link" type="button" id="remove-promotion" hidden>remove</button>
        </div>
        <p class="message" id="promotion-state"></p>

        <div class="totals">
            <div>Subtotal: <strong id="cart-subtotal">—</strong></div>
            <div>Discount: <strong id="cart-discount">—</strong></div>
            <div>Total: <strong id="cart-total">—</strong></div>
        </div>

        <div class="actions">
            <a id="cart-checkout" href="{{ route('sandbox.checkout') }}"
               style="padding:.4rem .9rem;border-radius:6px;background:#0b5cad;color:#fff;text-decoration:none">Checkout</a>
            <a href="{{ route('sandbox.index') }}">Continue browsing</a>
        </div>
    </div>
@endsection

<script>
document.addEventListener('DOMContentLoaded', function () {
    var api = window.sandboxApi;
    var linesBody = document.getElementById('cart-lines');
    var loading = document.getElementById('cart-loading');
    var body = document.getElementById('cart-body');
    var error = document.getElementById('cart-error');
    var message = document.getElementById('cart-message');
    var priceNote = document.getElementById('cart-price-note');

    function fail(text) { error.textContent = text; error.hidden = false; message.hidden = true; }
    function ok(text) { message.textContent = text; message.hidden = false; error.hidden = true; }
    function clear() { error.hidden = true; message.hidden = true; }

    function cell(row, text) {
        var td = document.createElement('td');
        td.textContent = text;
        row.appendChild(td);

        return td;
    }

    function renderLine(line) {
        var row = document.createElement('tr');
        cell(row, line.product_name === null ? line.variation_id : line.product_name);
        cell(row, api.attributes(line.attributes) || '—');
        cell(row, line.sku === null ? '—' : line.sku);
        cell(row, line.price_available ? api.money(line.unit_price) : 'price unavailable');

        var quantity = cell(row, '');
        quantity.className = 'qty';
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.value = String(line.quantity);
        var update = document.createElement('button');
        update.type = 'button';
        update.className = 'link';
        update.textContent = 'update';
        update.addEventListener('click', async function () {
            clear();
            try {
                await api.patch('/api/cart/lines/' + encodeURIComponent(line.variation_id), { quantity: Number(input.value) });
                await load();
            } catch (failure) { fail(failure.message); }
        });
        quantity.appendChild(input);
        quantity.appendChild(document.createTextNode(' '));
        quantity.appendChild(update);

        cell(row, line.price_available ? api.money(line.line_total) : '—');

        var actions = cell(row, '');
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'link';
        remove.textContent = 'remove';
        remove.addEventListener('click', async function () {
            clear();
            try {
                await api.del('/api/cart/lines/' + encodeURIComponent(line.variation_id));
                ok('Removed ' + (line.product_name || line.variation_id) + '.');
                await load();
            } catch (failure) { fail(failure.message); }
        });
        actions.appendChild(remove);

        return row;
    }

    function render(cart) {
        var lines = cart.lines || [];
        linesBody.textContent = '';
        lines.forEach(function (line) { linesBody.appendChild(renderLine(line)); });

        document.getElementById('cart-subtotal').textContent = api.money(cart.subtotal);
        document.getElementById('cart-total').textContent = api.money(cart.total);

        var discount = cart.subtotal && cart.total ? cart.subtotal.minor - cart.total.minor : null;
        document.getElementById('cart-discount').textContent =
            discount === null ? '—' : (discount / 100).toFixed(2) + ' ' + cart.total.currency;

        var notes = [];
        var changed = lines.filter(function (line) { return line.price_changed_since_add; });
        var unavailable = lines.filter(function (line) { return !line.price_available; });

        if (changed.length > 0) {
            notes.push('The price of ' + changed.length + ' line(s) changed since you added them; the prices above are today\'s.');
        }

        if (unavailable.length > 0) {
            notes.push(unavailable.length + ' line(s) have no price configured any more and are excluded from the total.');
        }

        priceNote.textContent = notes.join(' ');
        priceNote.hidden = notes.length === 0;

        var promotion = cart.promotion;
        var applied = promotion !== null && promotion !== undefined;
        document.getElementById('promotion-state').textContent =
            applied ? 'Promotion applied: ' + (promotion.code || JSON.stringify(promotion)) : 'No promotion applied.';
        document.getElementById('remove-promotion').hidden = !applied;
        document.getElementById('cart-checkout').style.display = lines.length === 0 ? 'none' : '';

        if (lines.length === 0) { ok('Your cart is empty. Add something from a product page.'); }

        loading.hidden = true;
        body.hidden = false;
        api.refreshCartCount();
    }

    async function load() {
        try {
            render(await api.get('/api/cart'));
        } catch (failure) {
            loading.hidden = true;
            fail(failure.message);
        }
    }

    document.getElementById('apply-promotion').addEventListener('click', async function () {
        clear();
        try {
            await api.post('/api/cart/promotion', { code: document.getElementById('promotion-code').value });
            await load();
            ok('Promotion applied.');
        } catch (failure) { fail(failure.message); }
    });

    document.getElementById('remove-promotion').addEventListener('click', async function () {
        clear();
        try {
            await api.del('/api/cart/promotion');
            await load();
            ok('Promotion removed.');
        } catch (failure) { fail(failure.message); }
    });

    load();
});
</script>
