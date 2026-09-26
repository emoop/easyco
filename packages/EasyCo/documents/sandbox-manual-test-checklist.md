# Sandbox storefront — manual test checklist (stage D)

Manual pass for the sandbox storefront: `/ _sandbox` (no space — the real path is `/_sandbox`).
D1/D2/D3 were staged earlier; D4 (cart), D5 (checkout) and D6 (confirmation) are staged by this
stage. Automated coverage lives in `tests/Feature/Sandbox/` and
`tests/Feature/{CartLineDisplayFieldsTest,CheckoutResponseOrderLinesTest}.php`; this file is what a
human runs in a real browser, where cookies, `sessionStorage` and CSRF actually exist.

## Before you start

1. `EASYCO_SANDBOX=true` in `.env`, `APP_ENV=local`.
2. `php artisan serve` (the host must be in `SANCTUM_STATEFUL_DOMAINS`; `localhost`/`127.0.0.1`
   are already covered).
3. A seeded product with a price and stock: one SIMPLE, one VARIABLE with two purchasable
   variations, and one variation switched to non-purchasable.
4. A promotion with a code for scenario 7 (e.g. `DEMO10`, 10%).
5. Open the browser's dev tools on the Network tab and keep `sessionStorage` visible.

## The eleven scenarios

1. **Sandbox is findable and unmistakable.** `/_sandbox` lists products; the red banner, the
   `noindex` meta and the `X-Robots-Tag: noindex` response header are all present.
2. **Header cart count.** On the product page the header shows `cart (0)`; add a line and it
   becomes the quantity you added without a reload of the count logic (the count is read from
   `GET /api/cart`, never from local state).
3. **SIMPLE product.** Its page shows stock and purchasable state under the price, plus one
   add-to-cart control when purchasable — and no variations table with a fake single row.
4. **VARIABLE product.** Each purchasable row has its own quantity + add control; the
   non-purchasable row has none, and no control anywhere can add it.
5. **Cart page shows real lines.** `/_sandbox/cart` lists product name, attributes (`Size: M`),
   SKU, unit price, quantity, line total. Update quantity, then remove a line: both take effect
   after a re-read of `GET /api/cart`.
6. **Cart reflects the API's own rules.** A line whose price was removed shows
   "price unavailable" and is excluded from the total (not an error page). A line whose price
   changed shows the change note.
7. **Promotion.** Apply a real code: the discount appears in the total and the promotion line
   names the code. Apply a bogus code: a clean 422 message, no crash, cart unchanged. Remove the
   promotion: totals return to the undiscounted value.
8. **Checkout page.** `/_sandbox/checkout` lists exactly the payment methods the application
   resolves, with readable labels, and shows a summary of the cart being checked out.
9. **Pickup point toggles.** Choosing "Pickup point" hides the street-address fields and shows
   carrier / pickup point / settlement (and vice versa) — no stale hidden values are submitted.
10. **A real order — and a second one right after.** Complete checkout: you land on the
    confirmation page, which shows the order id, its status, the lines (name, attributes, SKU,
    quantity, final price, promotion discount, net paid), the totals and the payment. Stock in
    the admin drops by the ordered quantity, and the cart you just bought is **no longer your
    current cart**: the cart page shows an empty cart, and your next add starts a NEW one (a
    guest's next cart reuses the same session token — `cart-domain-design.md` §14.1).

    Buy again in the same session: it is a **second order**, not a replay of the first — the
    defect this fix removed. Re-submitting the FIRST cart's checkout (the same `cart_id`, as a
    double-clicked button does) still returns that first order with `already_placed: true`,
    which is the protection the fix had to keep (§14.2). Naming another customer's `cart_id`
    returns 404 and never their order.
11. **Confirmation is browser-local, and failures are clean.** Reload the confirmation page: it
    still shows the order (from `sessionStorage`). Open `/_sandbox/order-placed/1` (or any id):
    **404** — there is no order-by-id route and no way to read someone else's order. Clear
    `sessionStorage` and reload: the page explains there is nothing to show, without an error.
    Finally, with dev tools, delete the `XSRF-TOKEN` cookie and try to add a line: the API
    rejects it (419/422), proving the pages did not weaken the API's CSRF protection.

## What to look for while doing this

- No sandbox page ever writes to the database itself: every write in the Network tab goes to
  `/api/cart...` or `/api/checkout`.
- Any 4xx the API returns is shown as a readable message on the page, never as a raw JSON dump
  or a blank screen.
- The confirmation page never calls an order endpoint — only `GET /api/cart` (for the header
  count) appears in its Network tab.
