# EasyCo — Production Requirements

**Status:** Describes what a server needs to run EasyCo in production —
separate from `ai-installation-assistant.md`'s local-development
walkthrough. Host-agnostic by design: nothing here ties EasyCo to a
specific hosting provider. EasyCo is meant to be self-hostable on any
server meeting the checklist below, from a managed platform to a bare
VPS you administer yourself.

## Required

- **PHP 8.3+** and **Composer** (per this project's own `composer.json`).
- **`giggsey/libphonenumber-for-php-lite` (^9.0, installed: 9.0.41)** — Google's phone-number data, pure PHP, no extension and no service. It validates every phone
  (checkout, address book, order editor) and its data is updated by `composer update giggsey/libphonenumber-for-php-lite`. After the upgrade that
  introduced it, `php artisan migrate` converts the phones already stored to E.164 where they parse (counts are printed; the rest are left untouched).
- **MySQL or MariaDB — not SQLite.** Even in WAL mode, SQLite allows
  only one write transaction at a time; real concurrent activity (a POS
  sale and a web checkout happening at the same moment, for instance)
  needs the row-level locking only MySQL/MariaDB provide here. No
  schema changes are needed either way — every migration in this
  project already targets both drivers — this is purely a
  `DB_CONNECTION` choice.
- **A persistent queue worker running as a real system service** —
  Supervisor on Linux, NSSM (or an equivalent Windows service wrapper)
  on Windows, not a terminal window left open. Required for
  image-variant processing (the Media domain) to actually run; without
  it, uploaded images stay pending forever, and no customer email is ever
  sent. The worker command must serve the mail queues as well:
  `php artisan queue:work database --queue=mail-transactional,default,mail-marketing --sleep=3 --tries=3 --max-time=3600`
  (without `--queue` a worker serves only `default`, and mail jobs wait
  unprocessed; the order is the priority, so a campaign can never delay an
  order confirmation). After any deploy touching
  queued job code, the worker needs `php artisan queue:restart` (or an
  equivalent reload) — it silently keeps running the old code
  otherwise.
- **Node.js 20.19+ or 22.12+, for the build step only.** `npm run
  build` compiles static assets at deploy time; no Node process needs
  to stay running on the server afterward.

## Recommended for real traffic

- **Redis**, for the application cache and the queue driver. Not
  APCu — APCu is per-process/per-server, so behind more than one app
  server each one builds and holds its own independent cache, and a
  write on server A never invalidates the stale entry sitting on
  server B. Redis is a single shared store every app server reads from,
  which stops being optional the moment there's more than one server.
- **A full-page cache layer in front of the app — Varnish is the
  reference choice.** It sits between the web server and PHP for
  anonymous, cacheable responses (product/catalog pages), so a page
  that hasn't changed is served without touching PHP or the database
  at all. This is genuinely host-agnostic: Varnish is free, open-source
  software that installs on essentially any Linux server behind Nginx
  or Apache — it is not tied to any specific hosting company. Some
  managed hosts bundle it already; on a self-administered VPS (for
  instance, a low-cost provider with a self-installed control panel
  such as CloudPanel, aaPanel, or RunCloud), it's a standard,
  well-documented install. A CDN (Cloudflare or otherwise) is an
  optional, additive layer on top of this for geographic distribution
  only — never a requirement. EasyCo does not assume or depend on any
  specific CDN.
  - **Tell the application about its proxies (`TRUSTED_PROXIES`).**
    Behind Varnish, a load balancer or a CDN, `$request->ip()` is the
    PROXY's own address, identically, on every order, unless the app
    is told which proxies to believe. Set `TRUSTED_PROXIES` in `.env` to
    the comma-separated IP addresses and CIDR ranges of the proxies
    that sit directly in front of PHP (for example
    `10.0.0.5,10.0.1.0/24`); the app then believes the forwarded
    headers (`X-Forwarded-For`, `-Host`, `-Port`, `-Proto`) **only from
    those peers** and records the real client address on each order
    (`order-context-design.md` §7.4). Empty (the default) trusts no
    proxy: safe, but behind a proxy it records the proxy's address.
    **Never a wildcard** (`*` is dropped): the header is
    client-spoofable, and trusting it from anyone records whatever
    address an attacker likes. The client address is kept in full only
    for `privacy.ip_full_retention_days` (30 by default) and is then
    anonymised by the daily `privacy:anonymise-order-ips` command, so
    the **scheduler must run** (`php artisan schedule:run` every minute).
  - **Two daily commands keep personal data from outliving its purpose
    (both run by the scheduler, both log counts only, never a value):**
    `privacy:anonymise-order-ips` (the full client address of an order
    older than `privacy.ip_full_retention_days`, 30 by default, becomes
    its anonymised form) and `order-blocks:prune` (a cash-on-delivery
    block that ended more than 30 days ago is deleted; a permanent block
    stays until it is revoked). A new install, or an upgrade past the
    stage that added the identity keys, runs `php artisan migrate` (which
    back-fills the keys of existing orders); `php artisan
    orders:rebuild-identity-keys` repairs them if a rule ever changes.
  - **Not yet built into EasyCo's own application code as of this
    writing.** See `performance-and-channel-strategy.md` §1 for the
    caching architecture decision (caching belongs only in the
    `Persistence/Eloquent` layer — domain classes never know a cache
    exists) and the storefront frontend work for how anonymous,
    cacheable routes get separated from stateful ones. Cart, checkout,
    and account pages must never be cached, full stop — a page carrying
    someone's session could otherwise be served to a different visitor.
- **Object storage for media (product photos, category/brand images,
  banners, carousels, video), not local disk, once a store's media
  library grows.** EasyCo's own Media domain already writes through a
  storage abstraction (`MediaStorageAdapter`) precisely so this is a
  configuration choice, not an application-code change — any
  S3-compatible object storage (DigitalOcean Spaces, AWS S3, or a
  self-hosted MinIO) works via Laravel's standard `filesystems.php`
  disk configuration. How much storage a given store needs varies
  enormously by merchant — a handful of products with a few photos
  each looks nothing like a catalog of tens of thousands of items,
  each with several images, on top of category imagery, homepage
  banners/carousels, and video — so no specific figure is prescribed
  here. The risk isn't that local disk is invalid for a small store;
  it's that a store which starts small and never migrates off local
  disk risks real growing pains later (disk exhaustion, slower
  backups, harder server migration) that object storage sidesteps
  entirely by being effectively unbounded and provider-managed.
  Deciding this before a media library grows large, not after, avoids
  a disruptive later migration.

## Explicitly not required

- No CDN, no Cloudflare, no specific hosting provider. Everything above
  runs on a plain Linux or Windows server you fully control.
- No separate JS runtime kept alive in production — Node.js is a
  build-time tool only, per above.

## Where to go next

- `ai-installation-assistant.md` — the local-development walkthrough
  (composer install, migrations, first run). Start there to get EasyCo
  running to look at the code.
- `performance-and-channel-strategy.md` — the reasoning behind the
  caching architecture referenced above, and the planned admin UI
  (Filament) direction.
- `staff-access-domain-design.md` — the permission system every
  merchant-facing endpoint sits behind.