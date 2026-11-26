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
