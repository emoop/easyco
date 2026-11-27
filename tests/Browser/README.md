# Browser check of the checkout pickup picker

`checkout-pickup.spec.mjs` drives a REAL Chromium against the running site (default `https://easyco-main.test`, Laragon, real data) in three sessions:
a staff member logged in to /admin (the owner's own case), a guest, and a logged-in customer. It is **not part of CI** (it needs the running site and a
browser); CI keeps the fake-DOM tests (`tests/Feature/Storefront/Js/checkout-flow.mjs`).

The staff member and the customer are throwaway (`pw-*@example.invalid`, random passwords), created by `tests/Browser/support/users.php` before the run and
deleted after it. Never the owner's credentials.

    npm i -D @playwright/test                 # once; the browser binary is never committed
    npx playwright test tests/Browser         # screenshots and the request list land in tests/Browser/out/

Environment: `SITE_URL` (default https://easyco-main.test), `PW_CHROMIUM` (path to an installed chrome.exe when Playwright's own build is not downloaded),
`PW_HOST_RULES` (e.g. `MAP easyco-main.test 127.0.0.1`), `PW_PRODUCT` (a product slug of the site, default `pantaloni-rips`).

## COUR-2a.5: a place that is already filled in

More throwaway customers, each with a saved address (`support/users.php create` prints them all): `saved` (Харманли, 6450), `two` (two street addresses), `nopost` (no postcode),
`savedoffice` (a saved Speedy office too), `odd` (a city that is only the start of a name), `ambiguous_a` / `ambiguous_b` / `ambiguous_none` (two same-named places from the real
settlements table, with and without the postcode). The cases check that the office / locker list starts by itself for every courier and kind, that the postcode is required for a
delivery to an address, and that the order built from an auto-resolved office carries our point id (the order is captured and answered with a fake refusal: nothing is placed).
The login limiter is respected (a 429 is waited out); a request the local Apache answers from a half-booted app (a bare 500) is asked again.
