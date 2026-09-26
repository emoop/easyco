{{--
    THE ONE PIECE OF JAVASCRIPT THE SANDBOX USES — included by the layout, so every
    sandbox page has the same tiny fetch wrapper and the same header cart count.

    It talks to the REAL storefront API and adds nothing to it: same-origin
    credentials (so a guest's session cookie, and therefore the Cart API's own
    cart_token, is what identifies the cart — cart-domain-design.md §10), an
    Accept: application/json header, and the X-XSRF-TOKEN header read from the
    XSRF-TOKEN cookie Laravel itself sets. A write without that header is rejected
    by VerifyCsrfToken, which is a property of the API this page must not weaken —
    SandboxCartCheckoutTest asserts the rejection really happens.

    Vanilla and inline on purpose (D7): no build step, no framework, nothing to
    keep in sync with a package.json for a preview surface.
--}}
<script>
(function () {
    function csrfToken() {
        var row = document.cookie.split('; ').find(function (item) {
            return item.indexOf('XSRF-TOKEN=') === 0;
        });

        return row === undefined ? '' : decodeURIComponent(row.slice('XSRF-TOKEN='.length));
    }

    async function call(method, url, body) {
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var token = csrfToken();

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (token !== '') {
            headers['X-XSRF-TOKEN'] = token;
        }

        var response = await fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        var payload = null;

        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok) {
            var failure = new Error((payload && payload.message) || ('Request failed with status ' + response.status));
            failure.status = response.status;
            failure.payload = payload;

            throw failure;
        }

        return payload;
    }

    function money(amount) {
        if (amount === null || amount === undefined) {
            return '—';
        }

        return (amount.minor / 100).toFixed(2) + ' ' + amount.currency;
    }

    /** The ordered, human-readable attribute list the cart API returns per line. */
    function attributes(attributes) {
        return (attributes || []).map(function (attribute) {
            return attribute.name + ': ' + attribute.value;
        }).join(' · ');
    }

    window.sandboxApi = {
        get: function (url) { return call('GET', url); },
        post: function (url, body) { return call('POST', url, body); },
        patch: function (url, body) { return call('PATCH', url, body); },
        del: function (url) { return call('DELETE', url); },
        money: money,
        attributes: attributes,

        /** The header count: the real cart, read through the real API. */
        refreshCartCount: async function () {
            var element = document.getElementById('sandbox-cart-count');

            if (element === null) {
                return;
            }

            try {
                var cart = await call('GET', '/api/cart');
                element.textContent = String((cart.lines || []).reduce(function (total, line) {
                    return total + line.quantity;
                }, 0));
            } catch (error) {
                element.textContent = '?';
            }
        },

        /** D6: the confirmation page's ONLY source of truth — this browser's own storage. */
        storeOrder: function (order, payment) {
            sessionStorage.setItem('sandbox.lastOrder', JSON.stringify({ order: order, payment: payment }));
        },

        readOrder: function () {
            var raw = sessionStorage.getItem('sandbox.lastOrder');

            return raw === null ? null : JSON.parse(raw);
        },
    };

    window.sandboxApi.refreshCartCount();

    /**
     * D3's add-to-cart controls, wherever they are on the page (the SIMPLE product's one control, or one
     * per purchasable variation row). Only controls the server rendered with data-variation-id exist, so a
     * non-purchasable variation has no button to click — the page cannot offer what the API would refuse.
     */
    function wireAddToCart() {
        var containers = document.querySelectorAll('.add-to-cart[data-variation-id]');

        if (containers.length === 0) {
            return;
        }

        var feedback = document.createElement('p');
        feedback.className = 'message';
        containers[0].parentNode.insertBefore(feedback, containers[0]);

        containers.forEach(function (container) {
            var button = container.querySelector('button.add');
            var input = container.querySelector('input.qty-input');

            if (button === null || input === null) {
                return;
            }

            button.addEventListener('click', async function () {
                button.disabled = true;
                feedback.className = 'message';
                feedback.textContent = 'Adding…';

                try {
                    await window.sandboxApi.post('/api/cart/lines', {
                        variation_id: container.getAttribute('data-variation-id'),
                        quantity: Number(input.value),
                    });
                    feedback.className = 'message ok';
                    feedback.textContent = 'Added to cart.';
                    window.sandboxApi.refreshCartCount();
                } catch (failure) {
                    feedback.className = 'message error';
                    feedback.textContent = failure.message;
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireAddToCart);
    } else {
        wireAddToCart();
    }
})();
</script>
